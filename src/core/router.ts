import { logger } from '../helpers/logger.js';
import { BotController, type TelegramUpdate } from '../controllers/bot.js';

/**
 * Entry point for Telegram's webhook POST. Decodes the update and
 * hands it to BotController. Returns the HTTP status the server should
 * answer with (Telegram expects a fast 200 regardless of what the
 * update did).
 */
export async function handleWebhook(rawBody: string): Promise<number> {
  let update: unknown;
  try {
    update = JSON.parse(rawBody);
  } catch {
    update = null;
  }

  if (typeof update !== 'object' || update === null || Array.isArray(update)) {
    // Usually scanners hitting the webhook URL — logged at info so real
    // misconfigurations (wrong secret, wrong endpoint) are still
    // visible in app.log without drowning in errors.
    logger.write('info', 'Webhook received a non-JSON POST body — rejected with 400', {
      body: rawBody.slice(0, 200),
    });
    await logger.flush();
    return 400;
  }

  try {
    await new BotController().processUpdate(update as TelegramUpdate);
  } catch (error) {
    logger.write('error', `Unhandled router exception: ${error instanceof Error ? error.message : error}`, {
      stack: error instanceof Error ? (error.stack ?? '') : '',
    });
  }

  // Table writes are queued; settle them before Telegram sees the 200 so
  // /errors can't miss the row this update just produced.
  await logger.flush();
  return 200;
}
