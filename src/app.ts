import { Hono } from 'hono';
import type { Context } from 'hono';
import type { ContentfulStatusCode } from 'hono/utils/http-status';
import { Config } from './config.js';
import { runWithHost } from './core/host.js';
import { handleWebhook } from './core/router.js';
import { logger } from './helpers/logger.js';
import { Validator } from './helpers/validator.js';
import { TikwmService } from './services/tikwm.js';
import { Tool77Service } from './services/tool77.js';

import { HOME_HTML } from './views/indexHtml.js';

// Rendered once at cold start: the only dynamic bit is the bot username.
const homeHtml = HOME_HTML.replace(
  '{{BOT_USERNAME}}',
  escapeHtml(Config.get<string>('bot_username', 'YourBotUsername')),
);

export const app = new Hono();

// The Host is carried in an AsyncLocalStorage scope rather than a module
// global: a webhook update being processed while a browser loads the page
// would otherwise overwrite it mid-flight and point the bot's buttons at
// somebody else's origin.
app.use('*', (c, next) => runWithHost(c.req.header('host') ?? null, next));

/** Telegram webhook entry point — point setWebhook at this route's public HTTPS URL. */
const handleWebhookRoute = async (c: Context) => {
  const secret = Config.get<string>('webhook_secret', '');
  if (secret !== '') {
    const provided = c.req.header('x-telegram-bot-api-secret-token') ?? '';
    if (provided !== secret) {
      logger.write('info', 'Webhook rejected — secret token mismatch', {
        remote: c.req.header('x-forwarded-for') ?? c.req.header('x-real-ip') ?? null,
      });
      return c.text('Forbidden', 403);
    }
  }

  const status = await handleWebhook(await c.req.text());
  return c.body(null, status as ContentfulStatusCode);
};

app.post('/webhook', handleWebhookRoute);

/**
 * Short-link redirector for the bot's YouTube menu buttons. Inline
 * buttons can't carry raw googlevideo URLs — each resolved link is
 * 800–1500 chars, and a handful of them in one inline keyboard trips
 * Telegram's "reply markup is too long" limit. Buttons point here
 * instead (/dl?id=<tool77 cache id>&kind=video&h=1080 / kind=audio
 * &fmt=m4a); at tap time the cached tool77 response is re-resolved and
 * the browser is 302'd to the real CDN URL. The cache entry
 * (tool77_cache_ttl) is the lifetime of those buttons.
 */
const handleDl = async (c: Context) => {
  const id = (c.req.query('id') ?? '').replace(/[^a-f0-9]/gi, '');
  const kind = c.req.query('kind') === 'audio' ? 'audio' : 'video';
  const tool77 = new Tool77Service();

  const data = id !== '' ? await tool77.getCachedById(id) : null;
  if (!data) {
    logger.write('info', 'dl redirect on expired id', { id, kind });
    return c.text(
      'That download menu expired — send the YouTube link to the bot again.',
      404,
    );
  }

  let target: string | null = null;
  if (kind === 'video') {
    const height = Number.parseInt(c.req.query('h') ?? '0', 10) || 0;
    target = tool77.getVideoQualities(data).get(height)?.url ?? null;
    if (!target) {
      logger.write('warning', 'dl redirect: requested video quality not in cached response', { id, h: height });
    }
  } else {
    const fmt = (c.req.query('fmt') ?? '').replace(/[^a-z0-9]/gi, '').toLowerCase();
    target = tool77.getAudioFormats(data)[fmt]?.url ?? null;
    if (!target) {
      logger.write('warning', 'dl redirect: requested audio format not in cached response', { id, fmt });
    }
  }

  if (!target) {
    return c.text('That download menu expired — send the YouTube link to the bot again.', 404);
  }

  return c.redirect(target, 302);
};

app.get('/dl', handleDl);

/**
 * Streams a YouTube file through this server. googlevideo.com links
 * commonly reject a fetch from any IP other than whichever server
 * resolved them from tool77 — a visitor's own browser fetching the raw
 * URL directly is exactly that kind of mismatch, same root cause as
 * the 403 Telegram's servers hit when the bot passed a raw URL instead
 * of uploading the file itself. TikTok/Facebook's CDNs haven't shown
 * this problem, so their URLs are still handed to the browser directly
 * — see the AJAX handler below.
 */
const handleProxy = async (c: Context) => {
  const id = (c.req.query('id') ?? '').replace(/[^a-f0-9]/gi, '');
  const kind = c.req.query('kind') === 'audio' ? 'audio' : 'video';

  const tool77 = new Tool77Service();
  const data = id ? await tool77.getCachedById(id) : null;
  if (!data) {
    return c.text('That link expired — go back and fetch it again.', 404);
  }

  const format = kind === 'audio' ? tool77.getBestAudio(data) : tool77.getBestNormal(data);
  const sourceUrl = format ? tool77.resolveUrl(format) : null;
  if (!sourceUrl) {
    return c.text('File not available.', 404);
  }

  const safeName = String(data.title ?? 'download').replace(/[^A-Za-z0-9 _-]/g, '') || 'download';
  const extension = String(format?.extension ?? (kind === 'audio' ? 'm4a' : 'mp4'));

  let upstream: Response;
  try {
    upstream = await fetch(sourceUrl, {
      headers: {
        'User-Agent':
          'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        Referer: 'https://www.youtube.com/',
      },
      redirect: 'follow',
      signal: AbortSignal.timeout(120_000),
    });
  } catch (error) {
    logger.write('error', 'YouTube proxy stream failed', {
      id,
      kind,
      fetch_error: error instanceof Error ? error.message : String(error),
    });
    return c.text('File not available.', 502);
  }

  if (upstream.status >= 400) {
    // The browser just sees a truncated/empty file on failure — leave
    // the real cause in app.log.
    logger.write('error', 'YouTube proxy stream failed', { id, kind, http_code: upstream.status });
    return c.text('File not available.', 502);
  }

  return new Response(upstream.body, {
    headers: {
      'Content-Type': kind === 'audio' ? 'audio/mp4' : 'video/mp4',
      'Content-Disposition': `attachment; filename="${safeName}.${extension}"`,
    },
  });
};

app.get('/proxy', handleProxy);

/** Web downloader AJAX endpoint — same-file POST the landing page calls. */
const handleAjax = async (c: Context) => {
  const contentType = c.req.header('content-type') ?? '';
  let url = '';

  if (contentType.includes('application/json')) {
    const payload = await c.req.json().catch(() => ({}));
    url = String(payload?.url ?? '').trim();
  } else {
    const body = await c.req.parseBody().catch(() => ({}) as Record<string, unknown>);
    url = String(body?.url ?? '').trim();
  }

  const isSupported =
    Validator.isTikTokUrl(url) || Validator.isFacebookUrl(url) || Validator.isYouTubeUrl(url);

  if (url === '' || !isSupported) {
    return c.json({ success: false, message: 'Paste a TikTok, Facebook, or YouTube link.' });
  }

  if (Validator.isShortLink(url)) {
    url = await Validator.resolveRedirect(url);
  }

  // TikTok: TikwmService, never tool77 (that's Facebook/YouTube only).
  if (Validator.isTikTokUrl(url)) {
    const tikwm = new TikwmService();
    const data = await tikwm.fetch(url);

    if (!data) {
      return c.json({
        success: false,
        message: "Couldn't fetch that link — it may be private, deleted, or invalid.",
      });
    }

    const type = tikwm.detectType(data);
    return c.json({
      success: true,
      type,
      title: data.title ?? '',
      cover: data.cover ?? data.origin_cover ?? null,
      video_url: type === 'video' ? tikwm.getVideoUrl(data) : null,
      images: type === 'image' ? tikwm.getImages(data) : [],
      live_images: type === 'image' ? tikwm.getLiveImages(data) : [],
      audio_url: tikwm.getAudioUrl(data),
    });
  }

  const videoId = Validator.extractYouTubeId(url);
  if (videoId) {
    url = 'https://www.youtube.com/watch?v=' + videoId; // canonical form, same as the bot uses
  }

  // tool77 only accepts the plain https://www.facebook.com/<type>/<id>
  // shape — strip tracking queries etc. before it sees the link.
  url = Validator.normalizeFacebookUrl(url);

  const tool77 = new Tool77Service();
  const data = await tool77.fetch(url);

  if (!data) {
    return c.json({
      success: false,
      message: "Couldn't fetch that link — it may be private, deleted, or invalid.",
    });
  }

  // Only Facebook and YouTube reach tool77 — TikTok exited above.
  const isYouTube = Validator.isYouTubeUrl(url);
  const id = tool77.cacheId(url);
  const title = data.title ?? '';
  const cover = data.thumbnail ?? null;

  const audio = tool77.getBestAudio(data);
  const audioUrl =
    audio && audio.url
      ? isYouTube
        ? `/proxy?id=${encodeURIComponent(id)}&kind=audio`
        : tool77.resolveUrl(audio)
      : null;

  const video = tool77.getBestNormal(data);
  const videoUrl =
    video && video.url
      ? isYouTube
        ? `/proxy?id=${encodeURIComponent(id)}&kind=video`
        : tool77.resolveUrl(video)
      : null;

  if (!videoUrl) {
    return c.json({ success: false, message: 'No downloadable file found for that link.' });
  }

  return c.json({
    success: true,
    type: 'video',
    title,
    cover,
    video_url: videoUrl,
    images: [],
    audio_url: audioUrl,
  });
};

app.post('/ajax', handleAjax);
app.post('/', handleAjax);

app.get('/', (c) => c.html(homeHtml));

/**
 * Legacy PHP entry points — keep deployed links working.
 *
 * `index.php?dl=1…` and `?proxy=1…` are the URLs already sitting in
 * users' chat history (the old bot built its YouTube menu buttons from
 * them), and `/webhook.php` is the path Telegram is registered against
 * until set-webhook runs. Redirecting those to the landing page would
 * silently break both, so the legacy dispatch is reproduced here.
 */
app.on(['GET', 'POST'], '/index.php', async (c) => {
  const query = c.req.query();
  const method = c.req.method;

  if (method === 'GET' && query.dl !== undefined) return handleDl(c);
  if (method === 'GET' && query.proxy !== undefined) return handleProxy(c);
  if (method === 'POST' && query.ajax !== undefined) return handleAjax(c);

  // Old behaviour without any of those params: render the page.
  return method === 'POST' ? c.html(homeHtml) : c.redirect('/', 302);
});

app.post('/webhook.php', handleWebhookRoute);
app.get('/webhook.php', (c) => c.redirect('/webhook', 301));

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
