import { Config } from '../config.js';
import { logger } from '../helpers/logger.js';

export interface SearchItem {
  videoId: string | null;
  title: string;
  channelTitle: string;
}

export interface SearchResult {
  items: SearchItem[];
  nextPageToken: string | null;
}

/**
 * YouTube search via the official YouTube Data API v3 (search.list).
 * Requires config('google_api_key') with the API enabled in Google
 * Cloud Console — this one IS a documented, stable API.
 */
export class YoutubeSearchService {
  private static readonly API_URL = 'https://www.googleapis.com/youtube/v3/search';

  async search(query: string, maxResults = 10, pageToken: string | null = null): Promise<SearchResult> {
    const params = new URLSearchParams({
      part: 'snippet',
      q: query,
      type: 'video',
      maxResults: String(maxResults),
      key: Config.get<string>('google_api_key', ''),
    });
    if (pageToken) params.set('pageToken', pageToken);

    let response: Response;
    try {
      response = await fetch(`${YoutubeSearchService.API_URL}?${params}`, {
        signal: AbortSignal.timeout(15_000),
      });
    } catch (error) {
      logger.write('error', `YouTube search request error: ${error instanceof Error ? error.message : error}`, {
        query,
      });
      return { items: [], nextPageToken: null };
    }

    let data: any;
    try {
      data = await response.json();
    } catch {
      logger.write('warning', 'YouTube search API returned a non-JSON body', { query });
      return { items: [], nextPageToken: null };
    }

    if (!Array.isArray(data?.items)) {
      logger.write('warning', 'YouTube search API returned an error', { query, response: data });
      return { items: [], nextPageToken: null };
    }

    const items: SearchItem[] = (data.items as any[]).map((item) => ({
      videoId: item?.id?.videoId ?? null,
      title: item?.snippet?.title ?? '',
      channelTitle: item?.snippet?.channelTitle ?? '',
    }));

    return { items, nextPageToken: data.nextPageToken ?? null };
  }
}
