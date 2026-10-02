import { db, unwrap } from '../core/db.js';

/** Key/value feature toggles (force_join_enabled, maintenance_mode, ...). */
export class Setting {
  static async get(key: string, fallback: string | null = null): Promise<string | null> {
    const rows = unwrap(
      await db().from('settings').select('setting_value').eq('setting_key', key).limit(1),
      'Setting.get',
    );
    return rows.length > 0 ? rows[0].setting_value : fallback;
  }

  static async set(key: string, value: string): Promise<void> {
    unwrap(
      await db()
        .from('settings')
        .upsert({ setting_key: key, setting_value: value }, { onConflict: 'setting_key' }),
      'Setting.set',
    );
  }

  static async isTrue(key: string, fallback = false): Promise<boolean> {
    const value = await this.get(key, fallback ? '1' : '0');
    return value === '1';
  }
}
