import fs from 'node:fs/promises';
import path from 'node:path';
import { Config } from '../config.js';
import { logger } from '../helpers/logger.js';

export interface TelegramResult {
  ok?: boolean;
  result?: unknown;
  description?: string;
  [key: string]: unknown;
}

export type ReplyMarkup = { inline_keyboard: Array<Array<Record<string, string>>> };

type Params = Record<string, string | number | boolean | undefined>;

/** Media-group item: a bare URL, or an explicit {type, media} entry for live-photo videos. */
export type MediaGroupItem = string | { type: string; media: string };

/**
 * Thin wrapper around the Telegram Bot API. Every public method maps
 * to one Bot API method; all requests go through request().
 */
export class TelegramService {
  private readonly apiUrl: string;

  constructor() {
    const token = Config.get<string>('bot_token', '');
    this.apiUrl = `https://api.telegram.org/bot${token}/`;
  }

  private async request(
    method: string,
    params: Params | FormData = {},
    multipart = false,
  ): Promise<TelegramResult | null> {
    let body: BodyInit;
    const headers: Record<string, string> = {};

    if (multipart && params instanceof FormData) {
      body = params;
    } else if (multipart) {
      const form = new FormData();
      for (const [key, value] of Object.entries(params as Params)) {
        if (value !== undefined) form.append(key, String(value));
      }
      body = form;
    } else {
      const search = new URLSearchParams();
      for (const [key, value] of Object.entries(params as Params)) {
        if (value !== undefined) search.append(key, String(value));
      }
      body = search;
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }

    let response: Response;
    try {
      response = await fetch(this.apiUrl + method, {
        method: 'POST',
        headers,
        body,
        signal: AbortSignal.timeout(60_000),
      });
    } catch (error) {
      logger.write('error', `Telegram API request failed: ${error instanceof Error ? error.message : error}`, {
        method,
      });
      return null;
    }

    let data: unknown;
    try {
      data = await response.json();
    } catch {
      logger.write('error', 'Telegram API returned a non-JSON body', { method, status: response.status });
      return null;
    }

    const result = data as TelegramResult;
    if (typeof result !== 'object' || result === null || result.ok !== true) {
      logger.write('error', 'Telegram API returned an error', { method, response: result });
    }
    return typeof result === 'object' && result !== null ? result : null;
  }

  /** True when Telegram rejected the call specifically over Markdown entity parsing. */
  private isParseError(result: TelegramResult | null): boolean {
    return result !== null && result.ok !== true && String(result.description ?? '').includes('parse entities');
  }

  private json(params: Params, replyMarkup?: ReplyMarkup | null): Params {
    if (replyMarkup !== null && replyMarkup !== undefined) {
      params.reply_markup = JSON.stringify(replyMarkup);
    }
    return params;
  }

  async sendMessage(
    chatId: number,
    text: string,
    replyMarkup?: ReplyMarkup | null,
    parseMode = 'Markdown',
  ): Promise<TelegramResult | null> {
    const params: Params = {
      chat_id: chatId,
      text,
      parse_mode: parseMode,
      disable_web_page_preview: true,
    };
    this.json(params, replyMarkup);

    let result = await this.request('sendMessage', params);

    // One stray _ * ` [ in interpolated text makes Telegram reject the
    // whole message with "can't parse entities" — retry once without
    // formatting so the content still arrives (plain beats missing).
    if (this.isParseError(result)) {
      logger.write('warning', 'Markdown parse failed — resending without formatting', {
        method: 'sendMessage',
        chat_id: chatId,
      });
      delete params.parse_mode;
      result = await this.request('sendMessage', params);
    }
    return result;
  }

  async sendVideo(
    chatId: number,
    videoUrl: string,
    caption = '',
    replyMarkup?: ReplyMarkup | null,
  ): Promise<TelegramResult | null> {
    const params: Params = {
      chat_id: chatId,
      video: videoUrl,
      caption,
      parse_mode: 'Markdown',
      supports_streaming: true,
    };
    this.json(params, replyMarkup);
    return this.request('sendVideo', params);
  }

  /** Uploads a local file via multipart/form-data (for videos over the URL-fetch size limit). */
  async sendVideoLocal(
    chatId: number,
    filePath: string,
    caption = '',
    replyMarkup?: ReplyMarkup | null,
  ): Promise<TelegramResult | null> {
    const form = new FormData();
    const bytes = await fs.readFile(filePath);
    form.append('chat_id', String(chatId));
    form.append('video', new Blob([bytes], { type: 'video/mp4' }), path.basename(filePath));
    form.append('caption', caption);
    form.append('parse_mode', 'Markdown');
    form.append('supports_streaming', 'true');
    if (replyMarkup !== null && replyMarkup !== undefined) {
      form.append('reply_markup', JSON.stringify(replyMarkup));
    }
    return this.request('sendVideo', form, true);
  }

  async sendPhoto(
    chatId: number,
    photoUrl: string,
    caption = '',
    replyMarkup?: ReplyMarkup | null,
  ): Promise<TelegramResult | null> {
    const params: Params = { chat_id: chatId, photo: photoUrl, caption, parse_mode: 'Markdown' };
    this.json(params, replyMarkup);

    let result = await this.request('sendPhoto', params);

    // Same stray-markdown protection sendMessage() has — captions carry
    // user-sourced titles too.
    if (this.isParseError(result)) {
      logger.write('warning', 'Markdown parse failed — resending without formatting', {
        method: 'sendPhoto',
        chat_id: chatId,
      });
      delete params.parse_mode;
      result = await this.request('sendPhoto', params);
    }
    return result;
  }

  /**
   * Plain URLs go out as photos; entries shaped {type, media}
   * (live-photo slides as videos) pass through untouched. Telegram
   * allows at most 10 items per media group, so larger TikTok photo
   * carousels are split and sent as consecutive groups.
   */
  async sendMediaGroup(chatId: number, imageUrls: MediaGroupItem[]): Promise<TelegramResult | null> {
    let result: TelegramResult | null = null;
    for (let i = 0; i < imageUrls.length; i += 10) {
      const chunk = imageUrls.slice(i, i + 10);
      const media = chunk.map((item) =>
        typeof item === 'string' ? { type: 'photo', media: item } : item,
      );
      result = await this.request('sendMediaGroup', {
        chat_id: chatId,
        media: JSON.stringify(media),
      });
    }
    return result;
  }

  async sendAudio(
    chatId: number,
    audioUrl: string,
    caption = '',
    thumbUrl?: string | null,
    replyMarkup?: ReplyMarkup | null,
  ): Promise<TelegramResult | null> {
    const params: Params = { chat_id: chatId, audio: audioUrl, caption, parse_mode: 'Markdown' };
    if (thumbUrl) params.thumbnail = thumbUrl;
    this.json(params, replyMarkup);
    return this.request('sendAudio', params);
  }

  /** action: typing | upload_video | upload_photo | upload_audio | ... */
  async sendChatAction(chatId: number, action: string): Promise<TelegramResult | null> {
    return this.request('sendChatAction', { chat_id: chatId, action });
  }

  /**
   * Copies an existing message (any content type) into chatId — used to
   * replay stored ads from the admin's chat and to broadcast a
   * forwarded message to all users. The source message itself is not
   * quoted or attributed.
   */
  async copyMessage(chatId: number, fromChatId: number, messageId: number): Promise<TelegramResult | null> {
    return this.request('copyMessage', {
      chat_id: chatId,
      from_chat_id: fromChatId,
      message_id: messageId,
    });
  }

  async editMessageText(
    chatId: number,
    messageId: number,
    text: string,
    replyMarkup?: ReplyMarkup | null,
    parseMode = 'Markdown',
  ): Promise<TelegramResult | null> {
    const params: Params = {
      chat_id: chatId,
      message_id: messageId,
      text,
      parse_mode: parseMode,
      disable_web_page_preview: true,
    };
    this.json(params, replyMarkup);

    let result = await this.request('editMessageText', params);

    if (this.isParseError(result)) {
      logger.write('warning', 'Markdown parse failed — resending without formatting', {
        method: 'editMessageText',
        chat_id: chatId,
      });
      delete params.parse_mode;
      result = await this.request('editMessageText', params);
    }
    return result;
  }

  /** Pass null to clear the keyboard entirely. */
  async editMessageReplyMarkup(
    chatId: number,
    messageId: number,
    replyMarkup: ReplyMarkup | null,
  ): Promise<TelegramResult | null> {
    return this.request('editMessageReplyMarkup', {
      chat_id: chatId,
      message_id: messageId,
      reply_markup: JSON.stringify(replyMarkup ?? { inline_keyboard: [] }),
    });
  }

  async deleteMessage(chatId: number, messageId: number): Promise<TelegramResult | null> {
    return this.request('deleteMessage', { chat_id: chatId, message_id: messageId });
  }

  async answerCallbackQuery(
    callbackId: string,
    text = '',
    showAlert = false,
  ): Promise<TelegramResult | null> {
    return this.request('answerCallbackQuery', {
      callback_query_id: callbackId,
      text,
      show_alert: showAlert,
    });
  }

  /** channel like "@channelusername" */
  async getChatMember(channel: string, userId: number): Promise<TelegramResult | null> {
    return this.request('getChatMember', { chat_id: channel, user_id: userId });
  }

  async setWebhook(url: string, secretToken?: string | null): Promise<TelegramResult | null> {
    const params: Params = { url };
    if (secretToken) params.secret_token = secretToken;
    return this.request('setWebhook', params);
  }
}
