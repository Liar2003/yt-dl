<?php
/**
 * Web downloader — landing page + same-file AJAX endpoint.
 * POST ?ajax=1 with url=<TikTok/Facebook/YouTube link> returns JSON;
 * GET ?proxy=1&id=..&kind=video|audio streams a YouTube file through
 * this server (see the comment on that block for why); GET with
 * neither param renders the page. Facebook and YouTube extract through
 * Tool77Service exactly as the bot does — see that class's docblock
 * for how the response's url tokens get resolved into real, fetchable
 * links. TikTok goes through TikwmService instead, same as public/.
 */

require_once __DIR__ . '/app/autoload.php';

use App\Helpers\Logger;
use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\TikwmService;
use App\Services\Tool77Service;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['dl'])) {
    // Short-link redirector for the bot's YouTube menu buttons. Inline
    // buttons can't carry raw googlevideo URLs — each resolved link is
    // 800–1500 chars, and a handful of them in one inline keyboard
    // trips Telegram's "reply markup is too long" limit. Buttons point
    // here instead (?dl=1&id=<tool77 cache id>&kind=video&h=1080 /
    // kind=audio&fmt=m4a); at tap time the cached tool77 response is
    // re-resolved and the browser is 302'd to the real CDN URL. The
    // cache entry (tool77_cache_ttl) is the lifetime of those buttons.
    $id = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['id'] ?? ''));
    $kind = ($_GET['kind'] ?? '') === 'audio' ? 'audio' : 'video';

    $expired = function () {
        http_response_code(404);
        echo 'That download menu expired — send the YouTube link to the bot again.';
        exit;
    };

    $tool77 = new Tool77Service();
    $data = $id !== '' ? $tool77->getCachedById($id) : null;
    if (!$data) {
        Logger::write('info', 'dl redirect on expired id', ['id' => $id, 'kind' => $kind]);
        $expired();
    }

    if ($kind === 'video') {
        $height = (int) ($_GET['h'] ?? 0);
        $target = $tool77->getVideoQualities($data)[$height]['url'] ?? null;
        if (!$target) {
            Logger::write('warning', 'dl redirect: requested video quality not in cached response', ['id' => $id, 'h' => $height]);
            $expired();
        }
    } else {
        $fmt = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($_GET['fmt'] ?? '')));
        $target = $tool77->getAudioFormats($data)[$fmt]['url'] ?? null;
        if (!$target) {
            Logger::write('warning', 'dl redirect: requested audio format not in cached response', ['id' => $id, 'fmt' => $fmt]);
            $expired();
        }
    }

    header('Location: ' . $target);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['proxy'])) {
    // googlevideo.com links commonly reject a fetch from any IP other
    // than whichever server resolved them from tool77 — a visitor's
    // own browser fetching the raw URL directly (for the <video>
    // preview or the download link) is exactly that kind of mismatch,
    // same root cause as the 403 Telegram's servers hit when the bot
    // passed a raw URL instead of uploading the file itself. Streaming
    // it through this server here sidesteps that. TikTok/Facebook's
    // CDNs haven't shown this problem, so their URLs are still handed
    // to the browser directly — see the AJAX handler below.
    $id = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['id'] ?? ''));
    $kind = ($_GET['kind'] ?? '') === 'audio' ? 'audio' : 'video';

    $tool77 = new Tool77Service();
    $data = $id ? $tool77->getCachedById($id) : null;
    if (!$data) {
        http_response_code(404);
        echo 'That link expired — go back and fetch it again.';
        exit;
    }

    $format = $kind === 'audio' ? $tool77->getBestAudio($data) : $tool77->getBestNormal($data);
    $sourceUrl = $format ? $tool77->resolveUrl($format) : null;
    if (!$sourceUrl) {
        http_response_code(404);
        echo 'File not available.';
        exit;
    }

    $safeName = preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($data['title'] ?? 'download')) ?: 'download';
    $extension = (string) ($format['extension'] ?? ($kind === 'audio' ? 'm4a' : 'mp4'));

    header('Content-Type: ' . ($kind === 'audio' ? 'audio/mp4' : 'video/mp4'));
    header('Content-Disposition: attachment; filename="' . $safeName . '.' . $extension . '"');

    $ch = curl_init($sourceUrl);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Referer: https://www.youtube.com/'],
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {
            echo $chunk;
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    // The browser just sees a truncated/empty file on failure — leave
    // the real cause in app.log.
    if (curl_errno($ch) !== 0 || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) >= 400) {
        Logger::write('error', 'YouTube proxy stream failed', [
            'id'         => $id,
            'kind'       => $kind,
            'curl_error' => curl_error($ch) ?: null,
            'http_code'  => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        ]);
    }
    curl_close($ch);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET['ajax'])) {
    $url = trim($_POST['url'] ?? '');
    $isSupported = Validator::isTikTokUrl($url) || Validator::isFacebookUrl($url) || Validator::isYouTubeUrl($url);

    if ($url === '' || !$isSupported) {
        Response::json(['success' => false, 'message' => "Paste a TikTok, Facebook, or YouTube link."]);
        exit;
    }

    if (Validator::isShortLink($url)) {
        $url = Validator::resolveRedirect($url);
    }

    // TikTok: TikwmService, never tool77 (that's Facebook/YouTube only).
    if (Validator::isTikTokUrl($url)) {
        $tikwm = new TikwmService();
        $data = $tikwm->fetch($url);

        if (!$data) {
            Response::json(['success' => false, 'message' => "Couldn't fetch that link — it may be private, deleted, or invalid."]);
            exit;
        }

        $type = $tikwm->detectType($data);
        Response::json([
            'success'   => true,
            'type'      => $type,
            'title'     => $data['title'] ?? '',
            'cover'     => $data['cover'] ?? ($data['origin_cover'] ?? null),
            'video_url' => $type === 'video' ? $tikwm->getVideoUrl($data) : null,
            'images'    => $type === 'image' ? $tikwm->getImages($data) : [],
            'audio_url' => $tikwm->getAudioUrl($data),
        ]);
        exit;
    }

    $videoId = Validator::extractYouTubeId($url);
    if ($videoId) {
        $url = 'https://www.youtube.com/watch?v=' . $videoId; // canonical form, same as the bot uses
    }

    $tool77 = new Tool77Service();
    $data = $tool77->fetch($url);

    if (!$data) {
        Response::json(['success' => false, 'message' => "Couldn't fetch that link — it may be private, deleted, or invalid."]);
        exit;
    }

    // Only Facebook and YouTube reach tool77 — TikTok exited above.
    $isYouTube = Validator::isYouTubeUrl($url);
    $id = $tool77->cacheId($url);
    $title = $data['title'] ?? '';
    $cover = $data['thumbnail'] ?? null;

    $audio = $tool77->getBestAudio($data);
    $audioUrl = $audio && $audio['url']
        ? ($isYouTube ? ('index.php?proxy=1&id=' . urlencode($id) . '&kind=audio') : $tool77->resolveUrl($audio))
        : null;

    $video = $tool77->getBestNormal($data);
    $videoUrl = $video && $video['url']
        ? ($isYouTube ? ('index.php?proxy=1&id=' . urlencode($id) . '&kind=video') : $tool77->resolveUrl($video))
        : null;

    if (!$videoUrl) {
        Response::json(['success' => false, 'message' => "No downloadable file found for that link."]);
        exit;
    }

    Response::json([
        'success' => true, 'type' => 'video', 'title' => $title, 'cover' => $cover,
        'video_url' => $videoUrl, 'images' => [], 'audio_url' => $audioUrl,
    ]);
    exit;
}

// Replace with your bot's @username after deployment.
$botUsername = 'YourBotUsername';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reel — TikTok, Facebook &amp; YouTube Downloader</title>
<meta name="description" content="Paste a TikTok, Facebook, or YouTube link and get the file back. No watermark, no sign-up.">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          ink: '#12151B',
          panel: '#1B2029',
          panel2: '#212836',
          hairline: '#2A3140',
          paper: '#ECEEF2',
          mute: '#8891A3',
          signal: '#3FE0C5',
          pulse: '#FF6452',
        },
        fontFamily: {
          display: ['"Space Grotesk"', 'sans-serif'],
          body: ['Inter', 'sans-serif'],
          mono: ['"JetBrains Mono"', 'monospace'],
        },
      }
    }
  }
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  body { background-color: #12151B; }

  .waveform-bar {
    animation: wave 1.1s ease-in-out infinite;
    transform-origin: bottom;
  }
  .waveform-bar:nth-child(1) { animation-delay: 0ms; }
  .waveform-bar:nth-child(2) { animation-delay: 90ms; }
  .waveform-bar:nth-child(3) { animation-delay: 180ms; }
  .waveform-bar:nth-child(4) { animation-delay: 270ms; }
  .waveform-bar:nth-child(5) { animation-delay: 360ms; }
  .waveform-bar:nth-child(6) { animation-delay: 270ms; }
  .waveform-bar:nth-child(7) { animation-delay: 180ms; }
  .waveform-bar:nth-child(8) { animation-delay: 90ms; }
  @keyframes wave {
    0%, 100% { transform: scaleY(0.25); }
    50%      { transform: scaleY(1); }
  }
  .waveform-idle .waveform-bar { animation-play-state: paused; transform: scaleY(0.2); }

  details > summary { list-style: none; cursor: pointer; }
  details > summary::-webkit-details-marker { display: none; }
  details[open] .faq-chevron { transform: rotate(180deg); }
  .faq-chevron { transition: transform 200ms ease; }

  ::selection { background: #3FE0C5; color: #12151B; }
  :focus-visible { outline: 2px solid #3FE0C5; outline-offset: 2px; }

  @media (prefers-reduced-motion: reduce) {
    .waveform-bar { animation: none !important; }
  }
</style>
</head>
<body class="bg-ink text-paper font-body antialiased">

  <!-- ============================== HEADER ============================== -->
  <header class="border-b border-hairline">
    <div class="max-w-5xl mx-auto px-6 py-5 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <svg width="20" height="20" viewBox="0 0 20 20" class="text-signal shrink-0" fill="none">
          <rect x="1" y="7" width="2.4" height="6" rx="1.2" fill="currentColor"/>
          <rect x="5.5" y="3" width="2.4" height="14" rx="1.2" fill="currentColor"/>
          <rect x="10" y="8.5" width="2.4" height="3" rx="1.2" fill="currentColor"/>
          <rect x="14.5" y="1" width="2.4" height="18" rx="1.2" fill="currentColor"/>
        </svg>
        <span class="font-display font-semibold text-lg tracking-tight">reel</span>
      </div>
      <a href="https://t.me/<?= htmlspecialchars($botUsername) ?>"
         class="font-mono text-xs uppercase tracking-wider px-4 py-2 rounded-full border border-hairline text-mute hover:text-paper hover:border-signal transition-colors">
        Open in Telegram →
      </a>
    </div>
  </header>

  <!-- ============================== HERO ================================ -->
  <main>
    <section class="max-w-3xl mx-auto px-6 pt-16 pb-10 text-center">
      <p class="font-mono text-xs uppercase tracking-[0.2em] text-signal mb-5">No watermark · MP4 &amp; MP3</p>
      <h1 class="font-display font-semibold text-4xl sm:text-5xl leading-[1.1] tracking-tight text-paper mb-4">
        Paste a link.<br class="sm:hidden"> Press play.<br> Get your file.
      </h1>
      <p class="text-mute text-base sm:text-lg max-w-xl mx-auto mb-10">
        Drop in a TikTok video, a Facebook video, or a YouTube link — we'll pull the clean file straight back to your browser.
      </p>

      <!-- transport-bar styled input -->
      <form id="downloader-form" class="max-w-xl mx-auto">
        <div class="flex items-center gap-2 bg-panel border border-hairline rounded-full pl-5 pr-2 py-2 focus-within:border-signal transition-colors">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="text-mute shrink-0">
            <path d="M5 3l8 5-8 5V3z" fill="currentColor"/>
          </svg>
          <input
            id="url-input"
            type="url"
            required
            placeholder="Paste a TikTok, Facebook, or YouTube link…"
            class="flex-1 bg-transparent border-none outline-none font-mono text-sm text-paper placeholder-mute/70 py-2"
          >
          <button
            id="submit-btn"
            type="submit"
            aria-label="Fetch download"
            class="shrink-0 w-11 h-11 rounded-full bg-signal text-ink flex items-center justify-center hover:brightness-110 active:scale-95 transition disabled:opacity-60 disabled:cursor-not-allowed"
          >
            <svg id="btn-icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M8 2v8m0 0L4.5 6.5M8 10l3.5-3.5M3 13h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        </div>

        <!-- signature waveform, idle until a request is in flight -->
        <div id="waveform" class="waveform-idle flex items-end justify-center gap-1 h-6 mt-5" aria-hidden="true">
          <span class="waveform-bar w-1 h-6 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-4 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-6 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-3 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-6 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-3 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-6 rounded-full bg-signal/70"></span>
          <span class="waveform-bar w-1 h-4 rounded-full bg-signal/70"></span>
        </div>
      </form>

      <!-- result area, filled in by JS -->
      <div id="result-area" class="max-w-xl mx-auto mt-6 text-left"></div>
    </section>

    <!-- ============================== FEATURES ============================== -->
    <section class="max-w-4xl mx-auto px-6 py-14 border-t border-hairline">
      <div class="grid sm:grid-cols-3 gap-px bg-hairline rounded-2xl overflow-hidden">
        <div class="bg-ink p-6">
          <p class="font-mono text-xs text-signal mb-2">NO&nbsp;WM</p>
          <p class="font-display text-base font-medium mb-1">Clean, no watermark</p>
          <p class="text-sm text-mute">The file you get is the file you'd film — no logo bouncing in the corner.</p>
        </div>
        <div class="bg-ink p-6">
          <p class="font-mono text-xs text-signal mb-2">HD</p>
          <p class="font-display text-base font-medium mb-1">Original quality</p>
          <p class="text-sm text-mute">Pulled at the highest resolution the source served the video in.</p>
        </div>
        <div class="bg-ink p-6">
          <p class="font-mono text-xs text-signal mb-2">MP3</p>
          <p class="font-display text-base font-medium mb-1">Audio extraction</p>
          <p class="text-sm text-mute">Just want the sound? Pull the soundtrack on its own, one tap.</p>
        </div>
      </div>
    </section>

    <!-- ============================== HOW IT WORKS (timeline) ============================== -->
    <section class="max-w-4xl mx-auto px-6 py-14 border-t border-hairline">
      <h2 class="font-display text-2xl font-semibold text-center mb-12">How it works</h2>
      <div class="relative grid sm:grid-cols-3 gap-10 sm:gap-6">
        <div class="hidden sm:block absolute top-[7px] left-[16.6%] right-[16.6%] h-px bg-hairline"></div>

        <div class="relative text-center">
          <div class="mx-auto mb-4 w-3.5 h-3.5 rounded-full bg-signal ring-4 ring-ink relative z-10"></div>
          <p class="font-mono text-xs text-mute mb-2">00:00</p>
          <p class="font-display font-medium mb-1">Paste your link</p>
          <p class="text-sm text-mute">Copy a TikTok, Facebook, or YouTube share link and drop it into the box above.</p>
        </div>
        <div class="relative text-center">
          <div class="mx-auto mb-4 w-3.5 h-3.5 rounded-full bg-signal ring-4 ring-ink relative z-10"></div>
          <p class="font-mono text-xs text-mute mb-2">00:08</p>
          <p class="font-display font-medium mb-1">We fetch it</p>
          <p class="text-sm text-mute">Our server pulls the original file in a couple of seconds.</p>
        </div>
        <div class="relative text-center">
          <div class="mx-auto mb-4 w-3.5 h-3.5 rounded-full bg-signal ring-4 ring-ink relative z-10"></div>
          <p class="font-mono text-xs text-mute mb-2">00:16</p>
          <p class="font-display font-medium mb-1">Download</p>
          <p class="text-sm text-mute">Save the video, photos, or just the audio — your call.</p>
        </div>
      </div>
    </section>

    <!-- ============================== FAQ ============================== -->
    <section class="max-w-2xl mx-auto px-6 py-14 border-t border-hairline">
      <h2 class="font-display text-2xl font-semibold text-center mb-8">FAQ</h2>
      <div class="space-y-2">

        <details class="group bg-panel rounded-xl border border-hairline px-5 py-4">
          <summary class="flex items-center justify-between font-display font-medium text-sm">
            Is this free?
            <svg class="faq-chevron w-4 h-4 text-mute shrink-0" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </summary>
          <p class="text-sm text-mute mt-3">Yes — pasting a link and downloading the file costs nothing.</p>
        </details>

        <details class="group bg-panel rounded-xl border border-hairline px-5 py-4">
          <summary class="flex items-center justify-between font-display font-medium text-sm">
            Does it work with photo posts?
            <svg class="faq-chevron w-4 h-4 text-mute shrink-0" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </summary>
          <p class="text-sm text-mute mt-3">Yes — TikTok photo-carousel posts come back as a gallery you can save image by image.</p>
        </details>

        <details class="group bg-panel rounded-xl border border-hairline px-5 py-4">
          <summary class="flex items-center justify-between font-display font-medium text-sm">
            Can I get just the audio?
            <svg class="faq-chevron w-4 h-4 text-mute shrink-0" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </summary>
          <p class="text-sm text-mute mt-3">Every result includes a separate audio-only download link alongside the video.</p>
        </details>

        <details class="group bg-panel rounded-xl border border-hairline px-5 py-4">
          <summary class="flex items-center justify-between font-display font-medium text-sm">
            Does this work with Facebook and YouTube too?
            <svg class="faq-chevron w-4 h-4 text-mute shrink-0" viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </summary>
          <p class="text-sm text-mute mt-3">Yes — paste any TikTok, Facebook, or YouTube link above. For searching YouTube by title instead of a link, message the Telegram bot directly.</p>
        </details>

      </div>
    </section>
  </main>

  <!-- ============================== FOOTER ============================== -->
  <footer class="border-t border-hairline">
    <div class="max-w-5xl mx-auto px-6 py-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-mute">
      <p>reel — a small download helper.</p>
      <p>Please respect creators' work and each platform's terms of use.</p>
    </div>
  </footer>

  <script>
    const form = document.getElementById('downloader-form');
    const input = document.getElementById('url-input');
    const submitBtn = document.getElementById('submit-btn');
    const btnIcon = document.getElementById('btn-icon');
    const waveform = document.getElementById('waveform');
    const resultArea = document.getElementById('result-area');

    const ICON_DOWNLOAD = '<path d="M8 2v8m0 0L4.5 6.5M8 10l3.5-3.5M3 13h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>';
    const ICON_SPINNER = '<circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-dasharray="28" stroke-dashoffset="10"/>';

    function setLoading(isLoading) {
      submitBtn.disabled = isLoading;
      btnIcon.innerHTML = isLoading ? ICON_SPINNER : ICON_DOWNLOAD;
      btnIcon.classList.toggle('animate-spin', isLoading);
      waveform.classList.toggle('waveform-idle', !isLoading);
    }

    function escapeHtml(str) {
      const div = document.createElement('div');
      div.textContent = str ?? '';
      return div.innerHTML;
    }

    function showError(message) {
      resultArea.innerHTML = `
        <div class="bg-panel border border-pulse/30 rounded-xl px-5 py-4 text-sm text-pulse">
          ${escapeHtml(message)}
        </div>`;
    }

    function renderResult(data) {
      const title = data.title ? `<p class="text-sm text-mute mb-4 line-clamp-2">${escapeHtml(data.title)}</p>` : '';

      if (data.type === 'video') {
        resultArea.innerHTML = `
          <div class="bg-panel border border-hairline rounded-2xl p-5">
            <video src="${data.video_url}" poster="${data.cover ?? ''}" controls playsinline class="w-full rounded-xl bg-black mb-4 max-h-96"></video>
            ${title}
            <div class="flex flex-wrap gap-3">
              <a href="${data.video_url}" download target="_blank" rel="noopener"
                 class="font-mono text-xs uppercase tracking-wider px-4 py-2.5 rounded-full bg-signal text-ink hover:brightness-110 transition">
                Download video
              </a>
              ${data.audio_url ? `
              <a href="${data.audio_url}" download target="_blank" rel="noopener"
                 class="font-mono text-xs uppercase tracking-wider px-4 py-2.5 rounded-full border border-hairline text-paper hover:border-signal transition">
                Download audio
              </a>` : ''}
            </div>
          </div>`;
        return;
      }

      if (data.type === 'image') {
        const thumbs = (data.images || []).map((src, i) => `
          <a href="${src}" download target="_blank" rel="noopener" class="group relative block aspect-[3/4] rounded-lg overflow-hidden bg-panel2">
            <img src="${src}" alt="Slide ${i + 1}" class="w-full h-full object-cover">
            <span class="absolute inset-0 bg-ink/0 group-hover:bg-ink/40 transition-colors flex items-center justify-center">
              <span class="opacity-0 group-hover:opacity-100 font-mono text-[10px] uppercase text-paper transition-opacity">Save</span>
            </span>
          </a>`).join('');

        resultArea.innerHTML = `
          <div class="bg-panel border border-hairline rounded-2xl p-5">
            ${title}
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-4">${thumbs}</div>
            ${data.audio_url ? `
            <a href="${data.audio_url}" download target="_blank" rel="noopener"
               class="inline-block font-mono text-xs uppercase tracking-wider px-4 py-2.5 rounded-full border border-hairline text-paper hover:border-signal transition">
              Download audio
            </a>` : ''}
          </div>`;
        return;
      }

      showError("Got a response we didn't expect. Please try again.");
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = input.value.trim();
      if (!url) return;

      setLoading(true);
      resultArea.innerHTML = '';

      try {
        const res = await fetch('index.php?ajax=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'url=' + encodeURIComponent(url),
        });
        const data = await res.json();
        if (!data.success) {
          showError(data.message || 'Something went wrong — try a different link.');
        } else {
          renderResult(data);
        }
      } catch (err) {
        showError('Network error — please try again.');
      } finally {
        setLoading(false);
      }
    });
  </script>
</body>
</html>
