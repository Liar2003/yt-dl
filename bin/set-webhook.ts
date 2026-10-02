/**
 * One-off CLI helper: run `bun run set-webhook` on the server (or
 * locally with the same .env) to register the webhook URL with
 * Telegram.
 *
 * Equivalent curl command, if you prefer:
 *   curl -F "url=https://yourdomain.com/webhook" \
 *        -F "secret_token=<WEBHOOK_SECRET>" \
 *        https://api.telegram.org/bot<TOKEN>/setWebhook
 */
import { Config } from '../src/config.js';
import { TelegramService } from '../src/services/telegram.js';

const url = Config.get<string>('webhook_url', '');
if (!url) {
  console.error('WEBHOOK_URL is not set — fill in .env first.');
  process.exit(1);
}

const secret = Config.get<string>('webhook_secret', '') || null;
const telegram = new TelegramService();
const result = await telegram.setWebhook(url, secret);

console.log(JSON.stringify(result, null, 2));
if (result?.ok !== true) process.exit(1);
