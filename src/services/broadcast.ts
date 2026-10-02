import { sleep } from '../helpers/text.js';
import { User } from '../models/user.js';
import type { TelegramService } from './telegram.js';

export interface BroadcastResult {
  success: number;
  failed: number;
  total: number;
}

/**
 * Sends a message to every non-banned user (/broadcast admin command).
 * Throttled to stay under Telegram's ~30 msg/sec global rate limit.
 */
export class BroadcastService {
  constructor(private readonly telegram: TelegramService) {}

  async send(message: string): Promise<BroadcastResult> {
    const ids = await User.allActiveTelegramIds();
    let success = 0;
    let failed = 0;

    for (const id of ids) {
      const result = await this.telegram.sendMessage(id, message);
      if (result && result.ok === true) {
        success++;
      } else {
        failed++;
      }
      await sleep(50); // ~20 messages/sec
    }

    return { success, failed, total: ids.length };
  }

  /**
   * Broadcasts an existing message (any content type — photo, video,
   * text, whatever was forwarded) to every non-banned user by copying
   * it out of the admin's chat with the bot. Same throttle as send().
   */
  async sendForwarded(fromChatId: number, fromMessageId: number): Promise<BroadcastResult> {
    const ids = await User.allActiveTelegramIds();
    let success = 0;
    let failed = 0;

    for (const id of ids) {
      const result = await this.telegram.copyMessage(id, fromChatId, fromMessageId);
      if (result && result.ok === true) {
        success++;
      } else {
        failed++;
      }
      await sleep(50); // ~20 messages/sec
    }

    return { success, failed, total: ids.length };
  }
}
