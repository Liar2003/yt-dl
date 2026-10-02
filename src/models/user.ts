import { allRows, db, unwrap } from '../core/db.js';
import { Config } from '../config.js';

export interface UserRow {
  id: number;
  telegram_id: number;
  username: string | null;
  first_name: string | null;
  last_name: string | null;
  is_banned: boolean;
  joined_at: string;
  last_active_at: string;
}

interface TelegramUser {
  id: number;
  username?: string;
  first_name?: string;
  last_name?: string;
}

export class User {
  /**
   * Inserts a user on first contact, otherwise refreshes their profile
   * fields and last-active timestamp. Returns the row.
   *
   * Implemented as a single upsert on telegram_id: only the columns
   * listed here take part in the DO UPDATE clause, so joined_at and
   * is_banned are left alone for existing rows.
   */
  static async registerOrUpdate(tgUser: TelegramUser): Promise<UserRow> {
    unwrap(
      await db()
        .from('users')
        .upsert(
          {
            telegram_id: tgUser.id,
            username: tgUser.username ?? null,
            first_name: tgUser.first_name ?? null,
            last_name: tgUser.last_name ?? null,
            last_active_at: new Date().toISOString(),
          },
          { onConflict: 'telegram_id' },
        ),
      'User.registerOrUpdate',
    );

    const row = await this.findByTelegramId(tgUser.id);
    if (!row) throw new Error('User.registerOrUpdate: row vanished right after upsert');
    return row;
  }

  static async findByTelegramId(telegramId: number): Promise<UserRow | null> {
    const rows = unwrap(
      await db().from('users').select('*').eq('telegram_id', telegramId).limit(1),
      'User.findByTelegramId',
    );
    return rows.length > 0 ? rows[0] : null;
  }

  static async isBanned(telegramId: number): Promise<boolean> {
    const user = await this.findByTelegramId(telegramId);
    return user ? Boolean(user.is_banned) : false;
  }

  static async setBanned(telegramId: number, banned: boolean): Promise<void> {
    unwrap(
      await db().from('users').update({ is_banned: banned }).eq('telegram_id', telegramId),
      'User.setBanned',
    );
  }

  /**
   * True for the bootstrap admin from config OR anyone in the
   * `admins` table. The bootstrap answer needs no database, which is
   * what lets /setup reach AdminController even on a brand-new deploy.
   */
  static async isAdmin(telegramId: number): Promise<boolean> {
    if (telegramId === Config.get<number>('admin_telegram_id')) {
      return true;
    }
    const result = await db()
      .from('admins')
      .select('telegram_id', { count: 'exact', head: true })
      .eq('telegram_id', telegramId);
    if (result.error) throw new Error(`User.isAdmin: ${result.error.message}`);
    return (result.count ?? 0) > 0;
  }

  static async countActive(): Promise<number> {
    const result = await db()
      .from('users')
      .select('id', { count: 'exact', head: true })
      .eq('is_banned', false);
    if (result.error) throw new Error(`User.countActive: ${result.error.message}`);
    return result.count ?? 0;
  }

  static async addAdmin(telegramId: number): Promise<void> {
    unwrap(
      await db()
        .from('admins')
        .upsert({ telegram_id: telegramId }, { onConflict: 'telegram_id', ignoreDuplicates: true }),
      'User.addAdmin',
    );
  }

  /** True if a row was deleted; false means the ID wasn't in the admins table. */
  static async removeAdmin(telegramId: number): Promise<boolean> {
    const rows = unwrap(
      await db().from('admins').delete().eq('telegram_id', telegramId).select('telegram_id'),
      'User.removeAdmin',
    );
    return rows.length > 0;
  }

  static async listAdmins(): Promise<Array<{ telegram_id: number; added_at: string }>> {
    return allRows<{ telegram_id: number; added_at: string }>(
      (from, to) =>
        db()
          .from('admins')
          .select('telegram_id, added_at')
          .order('id', { ascending: true })
          .range(from, to),
      'User.listAdmins',
    );
  }

  static async allActiveTelegramIds(): Promise<number[]> {
    const rows = await allRows<{ telegram_id: number }>(
      (from, to) =>
        db()
          .from('users')
          .select('telegram_id')
          .eq('is_banned', false)
          .order('id', { ascending: true })
          .range(from, to),
      'User.allActiveTelegramIds',
    );
    return rows.map((row) => Number(row.telegram_id));
  }
}
