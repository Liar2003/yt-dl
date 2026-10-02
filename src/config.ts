import 'dotenv/config';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Project root — the nearest directory above this file that holds a
 * package.json. Walking up (rather than assuming "../") keeps the
 * view file, log file and temp dir pointing at the project when the
 * compiled output lives in dist/.
 */
const rootDir = (() => {
  let dir = path.dirname(fileURLToPath(import.meta.url));
  for (let i = 0; i < 6; i++) {
    if (fs.existsSync(path.join(dir, 'package.json'))) return dir;
    const parent = path.dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }
  return process.cwd();
})();

function env(key: string, fallback = ''): string {
  const value = process.env[key];
  return value === undefined || value === '' ? fallback : value;
}

function envInt(key: string, fallback: number): number {
  const value = process.env[key];
  if (value === undefined || value === '') return fallback;
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? fallback : parsed;
}

/** Flat map of every setting, addressed by dot-notation — mirrors the PHP Config::get('a.b') helper. */
const data: Record<string, unknown> = {
  supabase: {
    url: env('SUPABASE_URL'),
    service_role_key: env('SUPABASE_SERVICE_ROLE_KEY'),
  },

  bot_token: env('BOT_TOKEN'),

  // Public HTTPS URL of this app's /webhook route.
  webhook_url: env('WEBHOOK_URL'),

  // Shared secret checked against Telegram's
  // X-Telegram-Bot-Api-Secret-Token header.
  webhook_secret: env('WEBHOOK_SECRET'),

  // Bootstrap admin — always treated as admin even before any row
  // exists in the `admins` table.
  admin_telegram_id: envInt('ADMIN_TELEGRAM_ID', 0),

  // @username shown on the web page's "Open in Telegram" link.
  bot_username: env('BOT_USERNAME', 'YourBotUsername'),

  log_file: resolvePath(env('LOG_FILE', path.join('storage', 'logs', 'app.log'))),
  cache_ttl: envInt('CACHE_TTL', 3600),

  // YouTube Data API v3 key, from Google Cloud Console.
  google_api_key: env('GOOGLE_API_KEY'),

  // Max video size (bytes) sent by URL before falling back to a local
  // download + multipart upload.
  max_url_upload_bytes: envInt('MAX_URL_UPLOAD_BYTES', 20 * 1024 * 1024),

  // How long tool77.com results stay cached (seconds). For YouTube this
  // doubles as the lifetime of the download-menu buttons. The resolved
  // googlevideo URLs themselves stay signed for ~6h.
  tool77_cache_ttl: envInt('TOOL77_CACHE_TTL', 3600),

  port: envInt('PORT', 3000),
  host: env('HOST', '0.0.0.0'),
  root_dir: rootDir,
};

/** Relative paths in .env are resolved against the project root, not the caller's cwd. */
function resolvePath(value: string): string {
  return path.isAbsolute(value) ? value : path.join(rootDir, value);
}

export class Config {
  static get<T = unknown>(key: string, fallback?: T): T {
    let value: unknown = data;
    for (const part of key.split('.')) {
      if (!value || typeof value !== 'object' || !(part in (value as Record<string, unknown>))) {
        return fallback as T;
      }
      value = (value as Record<string, unknown>)[part];
    }
    return (value === undefined ? fallback : value) as T;
  }
}
