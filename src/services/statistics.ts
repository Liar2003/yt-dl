import { db, unwrap } from '../core/db.js';
import { utcDate } from '../helpers/text.js';

export interface DailyStat {
  stat_date: string;
  downloads_count: number;
  new_users_count: number;
}

export interface TopDownloader {
  telegram_id: number;
  username: string | null;
  downloads: number;
}

/**
 * Daily rollup counters backing /admin and /stats. recordDownload() and
 * recordNewUser() are called from BotController as events happen.
 *
 * The increments run through the `stat_bump` Postgres function so two
 * concurrent events can't lose an update (a read-then-write here would
 * race); topUsers() goes through `top_downloaders` because Supabase's
 * query builder can't express GROUP BY + JOIN + COUNT in one round
 * trip. Both functions ship in database/schema.postgres.sql.
 */
export class StatisticsService {
  private async count(table: string): Promise<number> {
    const result = await db().from(table).select('id', { count: 'exact', head: true });
    if (result.error) throw new Error(`${table} count: ${result.error.message}`);
    return result.count ?? 0;
  }

  totalDownloads(): Promise<number> {
    return this.count('downloads');
  }

  totalUsers(): Promise<number> {
    return this.count('users');
  }

  async getLastDays(days = 7): Promise<DailyStat[]> {
    const since = utcDate(new Date(Date.now() - days * 86_400_000));
    return unwrap(
      await db()
        .from('statistics')
        .select('stat_date, downloads_count, new_users_count')
        .gte('stat_date', since)
        .order('stat_date', { ascending: true }),
      'StatisticsService.getLastDays',
    );
  }

  async recordDownload(): Promise<void> {
    unwrap(await db().rpc('stat_bump', { p_date: utcDate(), p_downloads: 1, p_new_users: 0 }), 'recordDownload');
  }

  async recordNewUser(): Promise<void> {
    unwrap(await db().rpc('stat_bump', { p_date: utcDate(), p_downloads: 0, p_new_users: 1 }), 'recordNewUser');
  }

  async topUsers(limit = 10): Promise<TopDownloader[]> {
    return unwrap(await db().rpc('top_downloaders', { p_limit: limit }), 'StatisticsService.topUsers');
  }
}
