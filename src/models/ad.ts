import { allRows, db, unwrap } from '../core/db.js';

export interface AdRow {
  id: number;
  source_chat_id: number;
  source_message_id: number;
  added_by: number;
  created_at: string;
}

/**
 * Promo messages an admin adds by forwarding them to the bot. Each row
 * just points at the original forwarded message (source_chat_id +
 * source_message_id, living in the admin's chat with the bot) — ads
 * are shown to end users via Telegram's copyMessage, re-reading from
 * that original message each time rather than storing its content.
 */
export class Ad {
  static async create(sourceChatId: number, sourceMessageId: number, addedBy: number): Promise<number> {
    const row = unwrap(
      await db()
        .from('ads')
        .insert({ source_chat_id: sourceChatId, source_message_id: sourceMessageId, added_by: addedBy })
        .select('id')
        .single(),
      'Ad.create',
    );
    if (!row) throw new Error('Ad.create: insert returned no row');
    return row.id;
  }

  /**
   * Supabase's query builder has no ORDER BY RANDOM(), but the ads
   * table holds a handful of rows at most — pull them all and pick in
   * process instead of paying for a Postgres RPC.
   */
  static async random(): Promise<AdRow | null> {
    const rows = await allRows<AdRow>(
      (from, to) => db().from('ads').select('*').order('id', { ascending: true }).range(from, to),
      'Ad.random',
    );
    if (!rows || rows.length === 0) return null;
    return rows[Math.floor(Math.random() * rows.length)];
  }

  static async all(): Promise<AdRow[]> {
    return allRows<AdRow>(
      (from, to) => db().from('ads').select('*').order('id', { ascending: false }).range(from, to),
      'Ad.all',
    );
  }

  static async delete(id: number): Promise<boolean> {
    const rows = unwrap(await db().from('ads').delete().eq('id', id).select('id'), 'Ad.delete');
    return rows.length > 0;
  }

  static async count(): Promise<number> {
    const result = await db().from('ads').select('id', { count: 'exact', head: true });
    if (result.error) throw new Error(`Ad.count: ${result.error.message}`);
    return result.count ?? 0;
  }
}
