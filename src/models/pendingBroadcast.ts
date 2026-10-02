import { db, unwrap } from '../core/db.js';

/**
 * Tracks which admin just ran /forward and is "armed" to have their
 * next forwarded message broadcast to everyone, instead of that
 * forward being auto-captured as an ad (see BotController's forward
 * detection — both features are triggered by an admin forwarding a
 * message, this is what tells them apart).
 */
export class PendingBroadcast {
  static async set(adminId: number): Promise<void> {
    unwrap(
      await db()
        .from('pending_broadcasts')
        .upsert({ admin_id: adminId, created_at: new Date().toISOString() }, { onConflict: 'admin_id' }),
      'PendingBroadcast.set',
    );
  }

  /** Also true only within maxAgeSeconds of /forward being run, so a forward days later isn't mistaken for a stale intent. */
  static async isPending(adminId: number, maxAgeSeconds = 600): Promise<boolean> {
    const cutoff = new Date(Date.now() - maxAgeSeconds * 1000).toISOString();
    const rows = unwrap(
      await db()
        .from('pending_broadcasts')
        .select('admin_id')
        .eq('admin_id', adminId)
        .gt('created_at', cutoff)
        .limit(1),
      'PendingBroadcast.isPending',
    );
    return rows.length > 0;
  }

  static async clear(adminId: number): Promise<void> {
    unwrap(
      await db().from('pending_broadcasts').delete().eq('admin_id', adminId),
      'PendingBroadcast.clear',
    );
  }
}
