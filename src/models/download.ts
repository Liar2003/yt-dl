import { db, unwrap } from '../core/db.js';

/** Shared by BotController and the poller so both write to the `downloads` log the same way. */
export class Download {
  static async record(userId: number, url: string, type: string): Promise<void> {
    unwrap(await db().from('downloads').insert({ user_id: userId, url, type }), 'Download.record');
  }

  /** Total completed downloads for one user — powers the /start greeting counter. */
  static async countForUser(userId: number): Promise<number> {
    const result = await db()
      .from('downloads')
      .select('id', { count: 'exact', head: true })
      .eq('user_id', userId);
    if (result.error) throw new Error(`Download.countForUser: ${result.error.message}`);
    return result.count ?? 0;
  }
}
