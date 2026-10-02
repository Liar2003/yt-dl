import { db, unwrap } from '../core/db.js';

export interface YoutubeDownloadRow {
  id: number;
  user_id: number;
  chat_id: number;
  title: string | null;
  progress_url: string;
  status: 'pending' | 'ready';
  download_url: string | null;
  thumbnail_url: string | null;
  created_at: string;
}

/**
 * All access to the youtube_downloads table — used by the on-demand
 * background poller (bin/poll-single.ts).
 */
export class YoutubeDownload {
  static async create(
    userId: number,
    chatId: number,
    title: string | null,
    progressUrl: string,
  ): Promise<number> {
    const row = unwrap(
      await db()
        .from('youtube_downloads')
        .insert({
          user_id: userId,
          chat_id: chatId,
          title,
          progress_url: progressUrl,
          status: 'pending',
        })
        .select('id')
        .single(),
      'YoutubeDownload.create',
    );
    if (!row) throw new Error('YoutubeDownload.create: insert returned no row');
    return row.id;
  }

  static async find(id: number): Promise<YoutubeDownloadRow | null> {
    const rows = unwrap(
      await db().from('youtube_downloads').select('*').eq('id', id).limit(1),
      'YoutubeDownload.find',
    );
    return rows.length > 0 ? rows[0] : null;
  }

  /**
   * Atomically flips pending -> ready and returns true only for the
   * ONE caller that wins the race — the background poller and a user
   * tapping "Check Progress" can both notice "ready" at nearly the
   * same moment; whoever's conditional UPDATE matches a row first is
   * the only one allowed to deliver the file.
   */
  static async claim(id: number): Promise<boolean> {
    const rows = unwrap(
      await db()
        .from('youtube_downloads')
        .update({ status: 'ready' })
        .eq('id', id)
        .eq('status', 'pending')
        .select('id'),
      'YoutubeDownload.claim',
    );
    return rows.length === 1;
  }

  static async remove(id: number): Promise<void> {
    unwrap(await db().from('youtube_downloads').delete().eq('id', id), 'YoutubeDownload.remove');
  }
}
