import fs from 'node:fs';
import path from 'node:path';
import { Config } from '../config.js';
import { db } from '../core/db.js';
import { utcDateTime } from './text.js';

export type LogLevel = 'info' | 'warning' | 'error';

const inflight = new Set<Promise<void>>();

/**
 * Writes to both the flat log file and the `logs` table. The file
 * write happens first so a DB outage never means total silence.
 */
class Logger {
  write(level: LogLevel, message: string, context: Record<string, unknown> = {}): void {
    const line = [
      `[${utcDateTime()}]`,
      `[${level.toUpperCase()}]`,
      message,
      Object.keys(context).length > 0 ? JSON.stringify(context) : '',
    ].join(' ');

    const logFile = Config.get<string>('log_file', '');
    if (logFile) {
      try {
        this.ensureLogDirectory(logFile);
        fs.appendFileSync(logFile, line + '\n');
      } catch {
        // Last resort so even an unwritable log path leaves a trace.
        console.error(`[app] ${line}`);
      }
    }

    try {
      // Supabase builders are lazy: the request only starts when the
      // thenable is awaited, so this must be chained explicitly rather
      // than `void`-ed. Errors resolve (not reject) as { error } — the
      // file log above already has the entry either way.
      const pending = (async () => {
        try {
          await db()
            .from('logs')
            .insert({ level, message, context: Object.keys(context).length > 0 ? JSON.stringify(context) : null })
            // Bounded so a hung Supabase connection can't stall the
            // webhook reply that flush() is waiting on.
            .abortSignal(AbortSignal.timeout(10_000));
        } catch {
          // Supabase unreachable/unconfigured — the file log already has it.
        }
      })();
      inflight.add(pending);
      void pending.finally(() => inflight.delete(pending));
    } catch {
      // Best effort — the file log above already has it.
    }
  }

  /**
   * Waits for queued `logs` inserts to settle, for at most `timeoutMs`.
   * Called before Telegram sees its 200 (and before a CLI script exits)
   * so /errors can't miss the row just written.
   */
  async flush(timeoutMs = 10_000): Promise<void> {
    const deadline = Date.now() + timeoutMs;
    while (inflight.size > 0) {
      const remaining = deadline - Date.now();
      if (remaining <= 0) return;
      await Promise.race([
        Promise.allSettled([...inflight]),
        new Promise((resolve) => setTimeout(resolve, remaining)),
      ]);
    }
  }

  /**
   * The storage/logs/ folder doesn't exist in a fresh clone (and git
   * won't carry empty directories) — create it on first write instead
   * of letting the entry vanish.
   */
  private ensureLogDirectory(logFile: string): void {
    const dir = path.dirname(logFile);
    if (dir && dir !== '.' && !fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true, mode: 0o775 });
    }
  }
}

export const logger = new Logger();
