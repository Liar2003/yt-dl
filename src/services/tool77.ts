import crypto from 'node:crypto';
import { Config } from '../config.js';
import { cacheGet, cacheSet } from '../core/cache.js';
import { logger } from '../helpers/logger.js';

export type FormatEntry = Record<string, any> & { url?: string };
export type Tool77Data = Record<string, any>;

/**
 * Client for tool77.com's "download/all" endpoint — used for Facebook
 * and YouTube only (BotController routes just those two here; TikTok
 * extraction deliberately goes through TikwmService instead).
 * Unofficial, undocumented third-party API. Three things worth knowing
 * before relying on this in production:
 *
 * 1. The `url` field in every format entry is NOT a direct link — it's
 *    base64_decode(strrev(token)). resolveUrl() does this decode and
 *    validates the result actually looks like a URL before returning
 *    it, so a scheme change on tool77's end fails loud (null) instead
 *    of handing Telegram garbage.
 *
 * 2. The decoded URLs are short-lived signed CDN links — YouTube's
 *    googlevideo.com ones carry their own `expire=` timestamp
 *    (typically hours out). This service's cache TTL
 *    (tool77_cache_ttl) is deliberately shorter than a typical
 *    "downloader" cache for that reason. YouTube menu buttons point at
 *    short /dl links and the real CDN URL is re-resolved from this
 *    cache only when tapped, so a button's lifetime is exactly this
 *    TTL.
 *
 * 3. tool77.com's own web UI gates downloads behind a bot-check, but
 *    that lives in their frontend — this service only POSTs the JSON
 *    endpoint directly. If tool77 ever extends that check to the raw
 *    API, fetch() starts getting non-"success" responses rather than
 *    failing silently; worth an occasional glance at app.log.
 */
export class Tool77Service {
  private static readonly API_URL = 'https://www.tool77.com/en/v/download/all/request';

  /** The only video heights YouTube menus offer — anything else (2160p, 144p, …) is dropped. */
  static readonly MENU_HEIGHTS = [1080, 720, 480, 360];

  private static md5(text: string): string {
    return crypto.createHash('md5').update(text).digest('hex');
  }

  async fetch(url: string): Promise<Tool77Data | null> {
    const id = this.cacheId(url);
    const cached = await this.getCache(id);
    if (cached !== null) {
      return cached;
    }

    let response: Response;
    try {
      response = await fetch(Tool77Service.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'User-Agent': 'Mozilla/5.0' },
        body: JSON.stringify({ url }),
        signal: AbortSignal.timeout(30_000),
      });
    } catch (error) {
      logger.write('error', `Tool77 request error: ${error instanceof Error ? error.message : error}`, { url });
      return null;
    }

    let decoded: any;
    try {
      decoded = await response.json();
    } catch {
      logger.write('warning', 'Tool77 API returned a non-JSON body', { url });
      return null;
    }

    if (decoded?.code !== 'success' || !decoded?.data) {
      logger.write('warning', 'Tool77 API returned an error', { url, response: decoded });
      return null;
    }

    const data = decoded.data as Tool77Data;
    try {
      await this.setCache(id, data);
    } catch (error) {
      logger.write('warning', 'Tool77 cache write failed — links will expire sooner', {
        key: 'tool77_' + id,
        db_error: error instanceof Error ? error.message : String(error),
      });
    }
    return data;
  }

  /** Deterministic short key for a URL — used both as the cache key and to reference a fetched result from callback_data (Telegram's 64-byte limit rules out embedding the full URL there). */
  cacheId(url: string): string {
    return Tool77Service.md5(url);
  }

  getCachedById(id: string): Promise<Tool77Data | null> {
    return this.getCache(id);
  }

  /** Best combined audio+video format from data['normals'] — the only entries guaranteed playable with sound as-is. */
  getBestNormal(data: Tool77Data): FormatEntry | null {
    return this.pickBest(asArray(data?.normals), true);
  }

  /** Best audio-only track from data['audios']. */
  getBestAudio(data: Tool77Data): FormatEntry | null {
    return this.pickBest(asArray(data?.audios), false);
  }

  /**
   * YouTube menu: download candidates for the fixed height ladder
   * (MENU_HEIGHTS, highest first). data['normals'] entries carry their
   * own audio track; data['videos'] ones are video-only — YouTube only
   * serves combined streams at 360p and below, so higher rungs always
   * come out of `videos` and play back silent. At each height a
   * combined format beats a video-only one, and among video-only
   * duplicates the most broadly playable codec wins (avc1 mp4 over
   * vp9/av01 webm). Entries whose url token fails to resolve are
   * skipped. Returns {} when nothing usable.
   */
  /**
   * Returns a Map rather than a plain object on purpose: JavaScript
   * enumerates integer-like object keys in ascending order, which
   * would silently reverse the 1080p→360p menu the ladder builds.
   */
  getVideoQualities(
    data: Tool77Data,
    heights: number[] | null = null,
  ): Map<number, { url: string; hasAudio: boolean }> {
    const ladder = heights ?? Tool77Service.MENU_HEIGHTS;

    const combined: Record<number, FormatEntry> = {};
    for (const entry of this.withHeight(asArray(data?.normals))) {
      if (!(entry.height in combined)) {
        combined[entry.height] = entry;
      }
    }

    const videoOnly: Record<number, FormatEntry> = {};
    for (const entry of this.withHeight(asArray(data?.videos))) {
      const h = entry.height;
      if (!(h in videoOnly) || this.codecRank(entry) < this.codecRank(videoOnly[h])) {
        videoOnly[h] = entry;
      }
    }

    const menu = new Map<number, { url: string; hasAudio: boolean }>();
    for (const h of ladder) {
      if (h in combined) {
        const url = this.resolveUrl(combined[h]);
        if (url) {
          menu.set(h, { url, hasAudio: true });
          continue;
        }
      }
      if (h in videoOnly) {
        const url = this.resolveUrl(videoOnly[h]);
        if (url) {
          menu.set(h, { url, hasAudio: false });
        }
      }
    }
    return menu;
  }

  /**
   * YouTube menu: best track (highest kb/s in its label) per audio
   * format — m4a and opus for typical videos. Ordered by how
   * universally playable each format is (m4a first).
   */
  getAudioFormats(data: Tool77Data): Record<string, { url: string; kbps: number }> {
    const best: Record<string, { kbps: number; entry: FormatEntry }> = {};

    for (const entry of asArray(data?.audios)) {
      if (!entry?.url) continue;
      const ext = String(entry.extension ?? '').toLowerCase();
      if (ext === '') continue;
      const kbps = kbpsOf(entry.label);
      if (!(ext in best) || kbps > best[ext].kbps) {
        best[ext] = { kbps, entry };
      }
    }

    const out: Record<string, { url: string; kbps: number }> = {};
    for (const [ext, info] of Object.entries(best)) {
      const url = this.resolveUrl(info.entry);
      if (url) out[ext] = { url, kbps: info.kbps };
    }

    return Object.fromEntries(
      Object.entries(out).sort(([a], [b]) => this.audioFormatRank(a) - this.audioFormatRank(b)),
    );
  }

  /** Drops entries without a positive height so callers can index on it safely. */
  private withHeight(entries: FormatEntry[]): Array<FormatEntry & { height: number }> {
    const out: Array<FormatEntry & { height: number }> = [];
    for (const entry of entries) {
      const height = Number(entry?.height ?? 0);
      if (entry?.url && height > 0) {
        out.push({ ...entry, height });
      }
    }
    return out;
  }

  /** Lower rank = more compatible player support. avc1-in-mp4 plays nearly everywhere. */
  private codecRank(entry: FormatEntry): number {
    const mime = String(entry?.mimeType ?? '').toLowerCase();
    if (mime.includes('avc1')) return 0;
    if (mime.includes('mp4')) return 1;
    return 2;
  }

  private audioFormatRank(ext: string): number {
    if (ext === 'm4a' || ext === 'mp3') return 0;
    if (ext === 'aac') return 1;
    return 2; // opus & friends: great quality, spotty native support
  }

  /**
   * Resolves a format entry's `url` token into a real, fetchable link.
   * Returns null (rather than a garbage string) if it doesn't decode to
   * something that looks like a URL.
   */
  resolveUrl(entry: FormatEntry | null | undefined): string | null {
    const token = entry?.url;
    if (typeof token !== 'string' || token === '') {
      return null;
    }
    const reversed = Array.from(token).reverse().join('');
    // PHP decoded with base64_decode(..., strict = true): one character
    // outside the alphabet rejected the token. Buffer.from would just
    // drop it and could still yield a well-formed (wrong) URL.
    if (!/^[A-Za-z0-9+/]*={0,2}$/.test(reversed)) {
      return null;
    }
    const decoded = Buffer.from(reversed, 'base64').toString('utf8');
    if (!/^https?:\/\//i.test(decoded)) {
      return null;
    }
    return decoded;
  }

  private pickBest(entries: FormatEntry[], isVideo: boolean): FormatEntry | null {
    const usable = entries.filter((entry) => Boolean(entry?.url));
    if (usable.length === 0) return null;
    const sorted = usable.sort((a, b) => this.score(b, isVideo) - this.score(a, isVideo));
    return sorted[0];
  }

  private score(entry: FormatEntry, isVideo: boolean): number {
    const quality = String(entry?.quality ?? '').toLowerCase();

    if (isVideo) {
      let score = 0;
      if (quality === 'watermark') score -= 10000; // plain "watermark" — avoid unless it's the only option
      if (quality.includes('hd')) score += 500;
      score += Math.round((Number(entry?.width ?? 0) * Number(entry?.height ?? 0)) / 1000);
      return score;
    }

    return kbpsOf(entry?.label);
  }

  private async getCache(key: string): Promise<Tool77Data | null> {
    return cacheGet<Tool77Data>('tool77_' + key);
  }

  private async setCache(key: string, value: Tool77Data): Promise<void> {
    await cacheSet('tool77_' + key, value, Config.get<number>('tool77_cache_ttl', 3600));
  }
}

function asArray(value: unknown): FormatEntry[] {
  return Array.isArray(value) ? (value as FormatEntry[]) : [];
}

/** Highest "… 128kb/s …" figure in a format label, or 0. */
function kbpsOf(label: unknown): number {
  const match = /(\d+)\s*kb\/s/i.exec(String(label ?? ''));
  return match ? Number.parseInt(match[1], 10) : 0;
}
