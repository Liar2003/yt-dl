import { db, unwrap } from '../core/db.js';

export interface LogRow {
  id: number;
  level: string;
  message: string;
  context: string | null;
  created_at: string;
}

export class Log {
  /** Most recent entries of one level ('info' | 'warning' | 'error') — backs /errors. */
  static async recentByLevel(level: string, limit = 50): Promise<LogRow[]> {
    return unwrap(
      await db().from('logs').select('*').eq('level', level).order('id', { ascending: false }).limit(limit),
      'Log.recentByLevel',
    );
  }
}
