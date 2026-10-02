import { Ad } from '../models/ad.js';
import { Setting } from '../models/setting.js';
import type { TelegramService } from './telegram.js';

/**
 * Shows one random stored ad after a successful download, when the
 * ads_enabled setting is on. Adding an ad happens elsewhere
 * (BotController detects an admin forwarding a message and calls
 * Ad.create() directly) — this service only handles the "show" side.
 */
export class AdsService {
  constructor(private readonly telegram: TelegramService) {}

  isEnabled(): Promise<boolean> {
    return Setting.isTrue('ads_enabled', false);
  }

  /** No-op if ads are off or none are stored — safe to call unconditionally after every successful delivery. */
  async maybeShow(chatId: number): Promise<void> {
    if (!(await this.isEnabled())) {
      return;
    }
    const ad = await Ad.random();
    if (!ad) {
      return;
    }
    // A failure here (e.g. the admin deleted the source message) is
    // logged by TelegramService's request() and otherwise ignored — a
    // broken ad should never disrupt someone's download.
    await this.telegram.copyMessage(chatId, ad.source_chat_id, ad.source_message_id);
  }
}
