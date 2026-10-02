import { logger } from './logger.js';

/** Stateless input validation / extraction helpers. */
export class Validator {
  static isTikTokUrl(text: string): boolean {
    return /https?:\/\/(www\.|vm\.|vt\.|m\.)?tiktok\.com\/\S+/i.test(text);
  }

  static isFacebookUrl(text: string): boolean {
    return /https?:\/\/((www\.|web\.|m\.)?facebook\.com\/|(www\.)?fb\.watch\/|fb\.com\/)\S+/i.test(text);
  }

  /**
   * Rewrites a Facebook URL into the exact shape tool77's API accepts —
   * "https://www.facebook.com/<type>/<id>". Anything extra, e.g. reel
   * links carrying tracking junk, comes back as a "This platform…" fail
   * from the API. So: fold m./web. hosts onto www., drop the query
   * string / fragment and any trailing slash everywhere except /watch/
   * pages, whose video ID lives in ?v=.
   */
  static normalizeFacebookUrl(url: string): string {
    if (!this.isFacebookUrl(url)) {
      return url;
    }

    // Canonical host: tool77 wants www.facebook.com.
    let out = url.replace(
      /^(https?:\/\/)(?:www\.|web\.|m\.)?(facebook\.com)(\/|$)/i,
      (_match, scheme: string, host: string, tail: string) => `${scheme}www.${host}${tail}`,
    );

    // /watch/ pages keep only the video-ID param — the ID lives in the query there.
    const watchMatch = /^(https?:\/\/www\.facebook\.com\/watch\/?)/i.exec(out);
    const videoIdMatch = /[?&]v=(\d+)/i.exec(out);
    if (watchMatch && videoIdMatch) {
      return watchMatch[1] + '?v=' + videoIdMatch[1];
    }

    // Everything else: cut query/fragment, then trailing slashes.
    return out.replace(/[?#].*$/, '').replace(/\/+$/, '');
  }

  static isYouTubeUrl(text: string): boolean {
    return /https?:\/\/(www\.|m\.)?(youtube\.com\/(watch\?v=|shorts\/)|youtu\.be\/)\S+/i.test(text);
  }

  /**
   * True for the short/redirecting variants of the supported platforms —
   * vm./vt.tiktok.com, youtu.be, fb.watch — whose real URL only appears
   * in the redirect chain, so they must be resolved (resolveRedirect)
   * before ID extraction / caching works.
   */
  static isShortLink(url: string): boolean {
    return /https?:\/\/((vm|vt)\.tiktok\.com\/|youtu\.be\/|(www\.)?fb\.watch\/)/i.test(url);
  }

  /**
   * Follows a short link's redirect chain and returns the final
   * destination URL. Falls back to the input unchanged if the request
   * fails or no final URL could be determined — callers treat the
   * result as "best-known canonical URL".
   */
  static async resolveRedirect(url: string): Promise<string> {
    let current = url;

    try {
      for (let hop = 0; hop < 5; hop++) {
        const response = await fetch(current, {
          method: 'HEAD',
          redirect: 'manual',
          signal: AbortSignal.timeout(10_000),
          headers: { 'User-Agent': 'Mozilla/5.0' },
        });

        if (response.status < 300 || response.status >= 400) {
          break;
        }

        const location = response.headers.get('location');
        if (!location) {
          break;
        }
        current = new URL(location, current).toString();
      }
    } catch (error) {
      logger.write('warning', 'Short-link redirect unresolved — using the input URL as-is', {
        url,
        fetch_error: error instanceof Error ? error.message : String(error),
      });
      return url;
    }

    if (!/^https?:\/\//i.test(current)) {
      logger.write('warning', 'Short-link redirect unresolved — using the input URL as-is', {
        url,
        effective: current,
      });
      return url;
    }

    return current;
  }

  /**
   * Pulls the 11-character video ID out of any standard YouTube URL
   * shape (watch?v=, youtu.be/, shorts/, embed/). Returns null for
   * anything else.
   */
  static extractYouTubeId(url: string): string | null {
    const match =
      /(?:youtube\.com\/(?:watch\?(?:[^\s]*&)?v=|shorts\/|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i.exec(url);
    return match ? match[1] : null;
  }

  /**
   * Pulls the first http(s) URL out of an arbitrary message, trimming
   * common trailing punctuation a user might paste along with it.
   */
  static extractUrl(text: string): string | null {
    const match = /https?:\/\/\S+/i.exec(text);
    return match ? match[0].replace(/[.,)]+$/, '') : null;
  }

  /**
   * HEAD request to check a remote file's size without downloading it.
   * Returns null if the size can't be determined.
   */
  static async getRemoteFileSize(url: string): Promise<number | null> {
    try {
      const response = await fetch(url, {
        method: 'HEAD',
        redirect: 'follow',
        signal: AbortSignal.timeout(10_000),
        headers: { 'User-Agent': 'Mozilla/5.0' },
      });
      const size = Number.parseInt(response.headers.get('content-length') ?? '', 10);
      if (!Number.isFinite(size) || size <= 0) {
        logger.write('info', 'Remote file size unavailable (no Content-Length) — sending by URL directly', { url });
        return null;
      }
      return size;
    } catch (error) {
      logger.write('warning', 'Remote file size check failed — falling back to direct URL delivery', {
        url,
        fetch_error: error instanceof Error ? error.message : String(error),
      });
      return null;
    }
  }

  /**
   * Escapes text for Telegram's legacy `parse_mode=Markdown` (what
   * TelegramService uses everywhere). Legacy Markdown only supports
   * backslash-escaping for these four characters — unlike MarkdownV2,
   * punctuation like . ! ( ) does not need escaping.
   */
  static markdownEscape(text: string): string {
    return text.replace(/[_*`\[]/g, (char) => '\\' + char);
  }
}
