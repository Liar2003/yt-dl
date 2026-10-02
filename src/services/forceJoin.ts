import { allRows, db, unwrap } from '../core/db.js';
import { logger } from '../helpers/logger.js';
import { Setting } from '../models/setting.js';
import type { ReplyMarkup, TelegramService } from './telegram.js';

export interface RequiredChannel {
  id: number;
  channel_username: string;
  channel_title: string | null;
}

/** "Force join" — require membership in one or more channels before a user can download anything. */
export class ForceJoinService {
  constructor(private readonly telegram: TelegramService) {}

  isEnabled(): Promise<boolean> {
    return Setting.isTrue('force_join_enabled', false);
  }

  async getChannels(): Promise<RequiredChannel[]> {
    return allRows<RequiredChannel>(
      (from, to) =>
        db().from('required_channels').select('*').order('id', { ascending: true }).range(from, to),
      'ForceJoinService.getChannels',
    );
  }

  /** True if force-join is off, no channels are configured, or the user has joined all of them. */
  async checkAll(userId: number): Promise<boolean> {
    if (!(await this.isEnabled())) {
      return true;
    }

    const channels = await this.getChannels();
    if (channels.length === 0) {
      return true;
    }

    for (const channel of channels) {
      const response = await this.telegram.getChatMember(channel.channel_username, userId);
      if (response === null || response.ok !== true) {
        // A failed API call here would otherwise strand the user at the
        // join prompt forever with no trace of why — log it, then treat
        // like "not joined" as before.
        logger.write('warning', 'Force-join membership check failed — treating user as not joined', {
          channel: channel.channel_username,
          user_id: userId,
          description: response?.description ?? null,
        });
      }
      const status = (response?.result as { status?: string } | undefined)?.status;
      if (status !== 'member' && status !== 'administrator' && status !== 'creator') {
        return false;
      }
    }

    return true;
  }

  async sendJoinPrompt(chatId: number): Promise<void> {
    const keyboard: Array<Array<{ text: string; url?: string; callback_data?: string }>> = [];
    for (const channel of await this.getChannels()) {
      keyboard.push([
        {
          text: '📢 ' + (channel.channel_title || channel.channel_username),
          url: 'https://t.me/' + channel.channel_username.replace(/^@+/, ''),
        },
      ]);
    }
    keyboard.push([{ text: '✅ Check Again', callback_data: 'check_join' }]);

    await this.telegram.sendMessage(
      chatId,
      "🔒 *Join required*\n\nPlease join the channel(s) below, then tap *Check Again*.",
      { inline_keyboard: keyboard } as ReplyMarkup,
    );
  }
}
