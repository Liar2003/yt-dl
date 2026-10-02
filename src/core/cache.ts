import { db, unwrap } from '../core/db.js';
import { logger } from '../helpers/logger.js';

/**
 * Generic TTL cache backed by the `cache` table — TikWM / Tool77 /
 * TikTok-user responses and stashed callback payloads all live here.
 *
 * The SQLite original compared `datetime(expires_at) > datetime('now')`
 * at read time; here expires_at is written as an ISO-8601 UTC instant
 * and compared with `.gt()`, which is Postgres's portable equivalent.
 *
 * Reads are best-effort (a broken cache just means refetching from the
 * upstream API); writes throw so callers that fall back — like the
 * stashed-callback-payload helper — can notice the failure.
 */
export async function cacheGet<T>(key: string): Promise<T | null> {
  try {
    const rows = unwrap(
      await db()
        .from('cache')
        .select('cache_value, expires_at')
        .eq('cache_key', key)
        .gt('expires_at', new Date().toISOString())
        .limit(1),
      'cacheGet',
    );
    if (rows.length === 0) return null;
    return JSON.parse(rows[0].cache_value) as T;
  } catch (error) {
    logger.write('warning', 'Cache read failed — refetching from source', {
      key,
      db_error: error instanceof Error ? error.message : String(error),
    });
    return null;
  }
}

export async function cacheSet<T>(key: string, value: T, ttlSeconds: number): Promise<void> {
  unwrap(
    await db()
      .from('cache')
      .upsert(
        {
          cache_key: key,
          cache_value: JSON.stringify(value),
          expires_at: new Date(Date.now() + ttlSeconds * 1000).toISOString(),
        },
        { onConflict: 'cache_key' },
      ),
    'cacheSet',
  );
}
