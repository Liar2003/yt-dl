import { Config } from '../config.js';
import { cacheGet, cacheSet } from '../core/cache.js';
import { logger } from '../helpers/logger.js';

export interface TikTokUserResult {
  videos: Array<Record<string, any>>;
  cursor: number;
  hasMore: boolean;
}

/**
 * TikWM's user/posts endpoint — lists a TikTok user's recent videos by
 * @username. Separate from Tool77Service on purpose: this is a
 * "browse and pick" discovery feature, not a single-link download, and
 * TikWM is the one that offers it (tool77's endpoint only takes a
 * single video URL, not a username).
 *
 * Delivery is NOT handled here. Once a video is picked from the list,
 * BotController reconstructs its canonical TikTok URL and hands off to
 * the existing handleTikTokUrl() — same TikwmService-backed pipeline
 * as any other TikTok link.
 */
export class TikTokUserService {
  private static readonly API_URL = 'https://www.tikwm.com/api/user/story';

  async fetchPosts(uniqueId: string, count = 12, cursor = 0): Promise<TikTokUserResult | null> {
    const query = new URLSearchParams({
      unique_id: '@' + uniqueId.replace(/^@+/, ''),
      count: String(count),
      cursor: String(cursor),
      web: '1',
      hd: '1',
    });

    let response: Response;
    try {
      response = await fetch(`${TikTokUserService.API_URL}?${query}`, {
        headers: { 'User-Agent': 'Mozilla/5.0' },
        signal: AbortSignal.timeout(20_000),
      });
    } catch (error) {
      logger.write('error', `TikTok user/posts request error: ${error instanceof Error ? error.message : error}`, {
        unique_id: uniqueId,
      });
      return null;
    }

    let decoded: any;
    try {
      decoded = await response.json();
    } catch {
      logger.write('warning', 'TikTok user/posts API returned a non-JSON body', { unique_id: uniqueId });
      return null;
    }

    if (decoded?.code !== 0 || !Array.isArray(decoded?.data?.videos)) {
      logger.write('warning', 'TikTok user/posts API returned an error', {
        unique_id: uniqueId,
        response: decoded,
      });
      return null;
    }

    const videos = decoded.data.videos as Array<Record<string, any>>;
    for (const video of videos) {
      await this.cacheVideo(video);
    }

    return {
      videos,
      cursor: Number(decoded.data.cursor ?? cursor + videos.length),
      // hasMore isn't confirmed present on every response — falling
      // back to "got a full page, there's probably more" if absent.
      hasMore: Boolean(decoded.data.hasMore ?? videos.length >= count),
    };
  }

  /** video_id is a TikTok snowflake ID — globally unique, so no need to key on the username too. */
  private async cacheVideo(video: Record<string, any>): Promise<void> {
    const id = String(video.video_id ?? video.id ?? '');
    if (id === '') return;
    try {
      await cacheSet('tkuser_video_' + id, video, Config.get<number>('cache_ttl', 3600));
    } catch (error) {
      logger.write('warning', 'TikTok user video cache write failed', {
        video_id: id,
        db_error: error instanceof Error ? error.message : String(error),
      });
    }
  }

  async getCachedVideo(videoId: string): Promise<Record<string, any> | null> {
    return cacheGet<Record<string, any>>('tkuser_video_' + videoId);
  }

}
