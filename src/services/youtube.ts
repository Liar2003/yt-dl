import { logger } from '../helpers/logger.js';

/**
 * YouTube -> audio conversion via the Savenow API.
 *
 * IMPORTANT: Savenow is an unofficial, undocumented third-party API.
 * The endpoint paths and response shape below follow the pattern used
 * by the "savenow.to"-style converters, but there is no stable public
 * API reference to verify field names against. Treat the URL constants
 * and the field names in the callers as the one thing to double check
 * against the real API before relying on this in production.
 *
 * This service deliberately has no sleep-and-poll-in-a-loop method.
 * checkProgress() is a single bounded HTTP call; whoever calls it
 * decides how many times and how often — bin/poll-single.ts is the
 * detached background poller that does that today.
 */
export class YoutubeService {
  private static readonly SAVENOW_INITIATE_URL = 'https://api.savenow.to/v1/init';
  private static readonly SAVENOW_PROGRESS_URL = 'https://api.savenow.to/v1/progress';

  /** Kicks off a conversion; returns {progress_url, title} or null. */
  async initiateDownload(
    url: string,
  ): Promise<{ progress_url: string; title: string | null } | null> {
    const response = await this.get(YoutubeService.SAVENOW_INITIATE_URL, { url, format: 'mp3' });
    if (!response?.progress_url) {
      logger.write('warning', 'YouTube initiateDownload failed', { url, response });
      return null;
    }
    return { progress_url: response.progress_url, title: response.title ?? null };
  }

  /**
   * A single poll — one bounded HTTP call, never a loop. Returns
   * {ready, ...} on a reachable server, or null if the request itself
   * failed. timeoutSeconds stays short for request-time callers, since
   * that call happens inline in a webhook response.
   */
  async checkProgress(
    progressUrl: string,
    timeoutSeconds = 8,
  ): Promise<{
    ready: boolean;
    download_url?: string | null;
    title?: string | null;
    thumbnail_url?: string | null;
  } | null> {
    const response = await this.get(progressUrl, {}, timeoutSeconds);
    if (!response) return null;
    if (Number(response.progress ?? 0) >= 1000) {
      return {
        ready: true,
        download_url: response.download_url ?? null,
        title: response.title ?? null,
        thumbnail_url: response.thumbnail_url ?? null,
      };
    }
    return { ready: false };
  }

  private async get(
    url: string,
    query: Record<string, string> = {},
    timeoutSeconds = 15,
  ): Promise<any | null> {
    let target = url;
    if (Object.keys(query).length > 0) {
      target += (url.includes('?') ? '&' : '?') + new URLSearchParams(query).toString();
    }

    try {
      const response = await fetch(target, {
        headers: { 'User-Agent': 'Mozilla/5.0' },
        signal: AbortSignal.timeout(timeoutSeconds * 1000),
      });
      const data = await response.json();
      return typeof data === 'object' && data !== null ? data : null;
    } catch (error) {
      logger.write('error', `YouTube service request error: ${error instanceof Error ? error.message : error}`, {
        url,
      });
      return null;
    }
  }

}
