import crypto from 'node:crypto';
import { Config } from '../config.js';
import { cacheGet, cacheSet } from '../core/cache.js';
import { db, unwrap } from '../core/db.js';
import { getRequestHost } from '../core/host.js';
import { logger } from '../helpers/logger.js';
import { mbSubstr, numberFormat, utcDateTime } from '../helpers/text.js';
import { Validator } from '../helpers/validator.js';
import { Download } from '../models/download.js';
import { PendingBroadcast } from '../models/pendingBroadcast.js';
import { Setting } from '../models/setting.js';
import { User } from '../models/user.js';
import { AdsService } from '../services/ads.js';
import { BroadcastService } from '../services/broadcast.js';
import { ForceJoinService } from '../services/forceJoin.js';
import { MediaService } from '../services/media.js';
import { StatisticsService } from '../services/statistics.js';
import { TelegramService, type ReplyMarkup } from '../services/telegram.js';
import { TikTokUserService } from '../services/tiktokUser.js';
import { TikwmService } from '../services/tikwm.js';
import { Tool77Service } from '../services/tool77.js';
import { YoutubeSearchService, type SearchResult } from '../services/youtubeSearch.js';
import { Ad } from '../models/ad.js';
import { AdminController } from './admin.js';

export interface TelegramUser {
  id: number;
  is_bot?: boolean;
  first_name?: string;
  last_name?: string;
  username?: string;
  language_code?: string;
  is_premium?: boolean;
}

export interface TelegramChat {
  id: number;
  type: string;
  title?: string;
}

export interface TelegramMessage {
  message_id: number;
  from?: TelegramUser;
  chat: TelegramChat;
  text?: string;
  forward_origin?: unknown;
  forward_date?: unknown;
  forward_from?: unknown;
  forward_from_chat?: unknown;
  forward_sender_name?: unknown;
}

export interface CallbackQuery {
  id: string;
  from?: TelegramUser;
  data?: string;
  message?: { message_id?: number; chat?: { id?: number } };
}

export interface TelegramUpdate {
  callback_query?: CallbackQuery;
  message?: TelegramMessage;
}

/**
 * Routes every incoming Telegram update to the right handler. This is
 * what the /webhook route calls into.
 *
 * Platform routing: Facebook and YouTube extract via Tool77Service —
 * one client for tool77.com's "download/all" endpoint (see that
 * class's docblock for how the response's obfuscated url tokens get
 * resolved into real, fetchable links, and the caveats that come with
 * an unofficial API). TikTok deliberately does NOT touch tool77: links
 * and /username post picks alike go through TikwmService, the same
 * client the web downloader uses. TikTokUserService is separate again —
 * it only handles browsing a TikTok user's video list; picking a video
 * from that list hands off to handleTikTokUrl() like any other TikTok
 * link.
 */
export class BotController {
  private readonly telegram = new TelegramService();
  private readonly tool77 = new Tool77Service();
  private readonly tikwm = new TikwmService();
  private readonly tiktokUser = new TikTokUserService();
  private readonly ytSearch = new YoutubeSearchService();
  private readonly media = new MediaService();
  private readonly forceJoin: ForceJoinService;
  private readonly stats = new StatisticsService();
  private readonly ads: AdsService;

  constructor() {
    this.forceJoin = new ForceJoinService(this.telegram);
    this.ads = new AdsService(this.telegram);
  }

  async processUpdate(update: TelegramUpdate): Promise<void> {
    if (update.callback_query) {
      await this.handleCallback(update.callback_query);
      return;
    }

    if (!update.message) {
      return;
    }

    const message = update.message;
    const from = message.from;
    const chatId = message.chat?.id;
    const text = (message.text ?? '').trim();

    if (!from || !chatId) {
      return;
    }

    const telegramId = Number(from.id);

    // Everything from here down to the command router touches the
    // database — on a brand-new deployment those tables don't exist
    // yet. Instead of dying before a single command gets handled, note
    // the failure and keep going: slash commands still route (that's
    // what makes the admin's /setup able to report on the missing
    // tables), while link traffic gets told the bot isn't ready.
    let dbReady = true;
    try {
      if ((await Setting.isTrue('maintenance_mode', false)) && !(await User.isAdmin(telegramId))) {
        await this.telegram.sendMessage(chatId, '🛠 The bot is under maintenance. Please try again shortly.');
        return;
      }

      const isNewUser = (await User.findByTelegramId(telegramId)) === null;
      await User.registerOrUpdate(from);
      if (isNewUser) {
        await this.stats.recordNewUser();
        await this.notifyAdminsNewUser(from, message);
      }

      if (await User.isBanned(telegramId)) {
        await this.telegram.sendMessage(chatId, "🚫 You've been banned from using this bot.");
        return;
      }

      // Any message an admin forwards to the bot is either the
      // broadcast they just armed with /forward, or — if they didn't —
      // gets auto-captured as an ad. Checked before the empty-text
      // return below since this content (a photo, a video) often has no
      // `text` field at all, only a caption or none.
      if ((await User.isAdmin(telegramId)) && this.isForwardedMessage(message)) {
        if (await PendingBroadcast.isPending(telegramId)) {
          await this.handleBroadcastForward(Number(chatId), telegramId, message);
        } else {
          await this.handleAdForward(Number(chatId), telegramId, message);
        }
        return;
      }
    } catch (error) {
      dbReady = false;
      logger.write('warning', `DB preamble failed — routing as unready: ${errorMessage(error)}`, {
        telegram_id: telegramId,
      });
    }

    if (text === '') {
      return;
    }

    if (text.startsWith('/')) {
      await this.handleCommand(text, Number(chatId), from);
      return;
    }

    if (!dbReady) {
      await this.telegram.sendMessage(chatId, "⚠️ The bot isn't set up yet — please check back soon.");
      return;
    }

    if (Validator.isTikTokUrl(text)) {
      await this.handleTikTokUrl(Number(chatId), telegramId, Validator.extractUrl(text) ?? text);
      return;
    }

    if (Validator.isFacebookUrl(text)) {
      await this.handleFacebookUrl(Number(chatId), telegramId, Validator.extractUrl(text) ?? text);
      return;
    }

    if (Validator.isYouTubeUrl(text)) {
      await this.handleYouTubeUrl(Number(chatId), telegramId, Validator.extractUrl(text) ?? text);
      return;
    }

    await this.handleTextSearch(Number(chatId), telegramId, text);
  }

  private async handleCommand(text: string, chatId: number, from: TelegramUser): Promise<void> {
    const command = text.split(' ')[0].split('@')[0].toLowerCase();

    switch (command) {
      case '/start':
        await this.telegram.sendMessage(
          chatId,
          '👋 *Welcome!*\n\n' +
            'I download videos from TikTok, Facebook & YouTube — free, no watermark.\n\n' +
            '*Just send me:*\n' +
            '• TikTok link → video/photos + 🎵 audio\n' +
            '• Facebook link → video + 🎵 audio\n' +
            '• YouTube link → pick 1080p–360p or 🎵 audio\n' +
            '• Any word → YouTube search results\n' +
            '• /username @handle → browse their videos\n\n' +
            'Type /help for everything I can do.' +
            (await this.personalStatsLine(Number(from.id))),
        );
        return;

      case '/help': {
        let help =
          '*How to use this bot*\n\n' +
          '*Downloads* — just paste a link:\n' +
          '• TikTok → video (no watermark), photo albums, 🎵 audio button\n' +
          '• Facebook → best video + 🎵 audio button\n' +
          '• YouTube → buttons: 1080p/720p/480p/360p 🔇 + 🎵 m4a/opus\n' +
          '• Any text → YouTube search, tap a result\n\n' +
          '*TikTok profiles*\n' +
          '• /username @handle → recent videos, tap to download\n\n' +
          '*Commands*\n' +
          '/start · /help · /about · /username';

        if (await this.isAdminSafe(Number(from.id))) {
          help +=
            '\n\n🛠 *Admin* — /admin for the dashboard\n' +
            '/users /ban /unban /history /list /top\n' +
            '/forcejoin /addchannel /removechannel /channels\n' +
            '/broadcast /forward /stats /logs /errors\n' +
            '/addadmin /removeadmin /admins\n' +
            '/ads /adslist /adsremove · forward = new ad\n' +
            '/maintenance /setup';
        } else {
          help += '\n\n💡 Download buttons last about an hour — resend the link if one expires.';
        }

        await this.telegram.sendMessage(chatId, help);
        return;
      }

      case '/about':
        await this.telegram.sendMessage(
          chatId,
          '🤖 *TikTok, Facebook & YouTube Downloader Bot*\nBuilt with Node.js, TypeScript & Supabase.',
        );
        return;

      case '/story': {
        const firstToken = text.split(/\s+/)[0];
        const arg = text.slice(firstToken.length).trim();
        if (arg === '') {
          await this.telegram.sendMessage(chatId, 'Usage: /username @tiktokhandle');
          return;
        }
        await this.handleUsernameLookup(chatId, Number(from.id), arg);
        return;
      }

      default:
        // User.isAdmin() answers from config alone for the bootstrap
        // admin, so /setup reaches AdminController even with no tables
        // yet. Any other admin check hits the DB and lands in the catch
        // below.
        try {
          if (await User.isAdmin(Number(from.id))) {
            await new AdminController().handleCommand(text, chatId, Number(from.id));
            return;
          }
          await this.telegram.sendMessage(chatId, 'Unknown command. Try /help.');
        } catch (error) {
          logger.write('error', `Admin dispatch failed: ${errorMessage(error)}`, {
            command,
            telegram_id: Number(from.id),
          });
          await this.telegram.sendMessage(
            chatId,
            "⚠️ Database not ready. If you're the bot owner, send /setup to check the schema.",
          );
        }
    }
  }

  /**
   * Admin check that can't throw: User.isAdmin() answers from config
   * alone for the bootstrap admin but hits the DB for everyone else,
   * so on a brand-new deployment it fails — treat that as non-admin
   * rather than breaking /help.
   */
  private async isAdminSafe(telegramId: number): Promise<boolean> {
    try {
      return await User.isAdmin(telegramId);
    } catch {
      return false;
    }
  }

  /**
   * Personal touch for returning users: their lifetime download count
   * appended to /start. Cosmetic only — a DB that isn't set up yet
   * (commands route before /setup) or briefly unreachable just yields
   * an empty string rather than failing the greeting.
   */
  private async personalStatsLine(telegramId: number): Promise<string> {
    try {
      const count = await Download.countForUser(telegramId);
      return count > 0
        ? `\n\n📊 You've downloaded *${numberFormat(count)}* ${count === 1 ? 'file' : 'files'} with me so far.`
        : '';
    } catch {
      return '';
    }
  }

  /**
   * Every brand-new user triggers a full-detail report to all admins
   * (the bootstrap admin_telegram_id from config plus everyone in the
   * admins table). Best-effort by design: this runs inside the DB
   * preamble where a half-set-up database is exactly the interesting
   * case, and a failing admin ping must never break the new user's own
   * request — so everything is wrapped.
   */
  private async notifyAdminsNewUser(from: TelegramUser, message: TelegramMessage): Promise<void> {
    try {
      const ids = [Config.get<number>('admin_telegram_id')];
      for (const admin of await User.listAdmins()) {
        ids.push(Number(admin.telegram_id));
      }

      const text = await this.buildNewUserReport(from, message);
      for (const id of [...new Set(ids)]) {
        // A new user who happens to be an admin shouldn't get a report about themselves.
        if (id > 0 && id !== Number(from.id)) {
          await this.telegram.sendMessage(id, text);
        }
      }
    } catch (error) {
      logger.write('warning', `New-user admin report failed: ${errorMessage(error)}`, {
        telegram_id: Number(from.id ?? 0),
      });
    }
  }

  private async buildNewUserReport(from: TelegramUser, message: TelegramMessage): Promise<string> {
    const name = `${from.first_name ?? ''} ${from.last_name ?? ''}`.trim();
    const username = from.username ?? '';

    const lines = [
      '🆕 *New User Alert*',
      '',
      '👤 Name: ' + Validator.markdownEscape(name !== '' ? name : '-'),
      '🔗 Username: ' + (username !== '' ? '@' + Validator.markdownEscape(username) : '-'),
      `🆔 ID: \`${from.id}\``,
      `🔗 [Open profile](tg://user?id=${from.id})`,
    ];

    if (from.language_code) {
      lines.push(`🌐 Language: ${from.language_code}`);
    }
    lines.push('💎 Premium: ' + (from.is_premium ? 'Yes' : 'No'));

    const chatType = message.chat?.type ?? '';
    let source: string;
    switch (chatType) {
      case 'private':
        source = 'Private chat';
        break;
      case 'supergroup':
        source = 'Group';
        break;
      case 'group':
        source = 'Basic group';
        break;
      case 'channel':
        source = 'Channel';
        break;
      default:
        source = chatType !== '' ? chatType.charAt(0).toUpperCase() + chatType.slice(1) : 'Unknown';
    }
    if ((chatType === 'group' || chatType === 'supergroup') && message.chat?.title) {
      source += ': ' + Validator.markdownEscape(message.chat.title);
    }
    lines.push(`💬 Via: ${source}`);

    try {
      const total = await this.stats.totalUsers();
      lines.push('');
      lines.push('👥 Total registered users: ' + numberFormat(total));
    } catch {
      // Count is garnish; skip it rather than fail the report.
    }

    lines.push('🕒 Joined: ' + utcDateTime() + ' UTC');
    return lines.join('\n');
  }

  /**
   * Telegram Bot API 7.0+ uses a unified `forward_origin` object;
   * older clients/API versions may still send the individual
   * forward_date/forward_from/forward_from_chat/forward_sender_name
   * fields instead — checking for any of them covers both.
   */
  private isForwardedMessage(message: TelegramMessage): boolean {
    // isset() semantics: a key present but null does not count, which is
    // what PHP checked — `in` would treat null as forwarded and swallow
    // an admin's message into the ad/broadcast capture path.
    const present = (value: unknown): boolean => value !== undefined && value !== null;
    return (
      present(message.forward_origin) ||
      present(message.forward_date) ||
      present(message.forward_from) ||
      present(message.forward_from_chat) ||
      present(message.forward_sender_name)
    );
  }

  /**
   * Stores a reference to the forwarded message (which now lives in
   * this admin's chat with the bot) rather than its content — see the
   * Ad model's docblock. Any message type works: text, photo, video,
   * document, whatever the admin forwards.
   */
  private async handleAdForward(chatId: number, adminId: number, message: TelegramMessage): Promise<void> {
    const messageId = Number(message.message_id ?? 0);
    if (!messageId) {
      return;
    }

    const id = await Ad.create(chatId, messageId, adminId);
    const status = (await this.ads.isEnabled()) ? '' : ' Ads are currently off — turn them on with /ads on.';
    await this.telegram.sendMessage(chatId, `✅ Ad #${id} saved.${status}`);
  }

  /** Consumes the /forward arming set by AdminController and sends the forwarded message to every user. */
  private async handleBroadcastForward(
    chatId: number,
    adminId: number,
    message: TelegramMessage,
  ): Promise<void> {
    await PendingBroadcast.clear(adminId);

    const messageId = Number(message.message_id ?? 0);
    if (!messageId) {
      return;
    }

    await this.telegram.sendMessage(chatId, '📣 Broadcasting to all active users…');
    const result = await new BroadcastService(this.telegram).sendForwarded(chatId, messageId);
    await this.telegram.sendMessage(
      chatId,
      `✅ Sent to ${result.success}/${result.total} users (${result.failed} failed).`,
    );
  }

  /**
   * TikTok: video primary (or a photo carousel), with a 🎵 Download
   * Audio button. Extraction runs through TikwmService — tool77 is
   * reserved for Facebook and YouTube only.
   */
  async handleTikTokUrl(chatId: number, userId: number, rawUrl: string): Promise<void> {
    let url = rawUrl;
    if (Validator.isShortLink(url)) {
      url = await Validator.resolveRedirect(url);
    }

    if (!(await this.forceJoin.checkAll(userId))) {
      await this.storePendingRequest(userId, chatId, url);
      await this.forceJoin.sendJoinPrompt(chatId);
      return;
    }

    await this.telegram.sendChatAction(chatId, 'typing');

    const data = await this.tikwm.fetch(url);
    if (!data) {
      await this.telegram.sendMessage(
        chatId,
        "❌ Couldn't fetch that TikTok link. It may be private, deleted, or invalid.",
      );
      return;
    }

    const title = Validator.markdownEscape(String(data.title ?? ''));
    const tiktokId = String(data.id ?? md5(url));
    const audioUrl = this.tikwm.getAudioUrl(data);
    await this.tikwm.cacheAudioUrl(tiktokId, audioUrl, url);

    const keyboard: ReplyMarkup | null = audioUrl
      ? { inline_keyboard: [[{ text: '🎵 Download Audio', callback_data: 'tkaud_' + tiktokId }]] }
      : null;

    const images = this.tikwm.getImages(data);
    if (images.length > 0) {
      // Slides backed by a TikTok live photo carry an MP4 in
      // live_images (index-paired with images) — send those as videos
      // so users get the animated version, not a still.
      const liveImages = this.tikwm.getLiveImages(data);
      await this.telegram.sendChatAction(chatId, liveImages.length > 0 ? 'upload_video' : 'upload_photo');
      await this.telegram.sendMediaGroup(chatId, this.buildCarouselMedia(images, liveImages));
      await this.telegram.sendMessage(chatId, title !== '' ? title : 'Here you go 👆', keyboard);
      await this.saveDownload(userId, url, 'image');
      await this.stats.recordDownload();
      await this.ads.maybeShow(chatId);
      return;
    }

    const videoUrl = this.tikwm.getVideoUrl(data);
    if (!videoUrl) {
      await this.telegram.sendMessage(chatId, '❌ No downloadable video found for that link.');
      return;
    }

    await this.deliverVideo(chatId, videoUrl, title, keyboard);
    await this.saveDownload(userId, url, 'video');
    await this.stats.recordDownload();
    await this.ads.maybeShow(chatId);
  }

  /**
   * Pairs carousel slides with their live-photo videos: slide i with a
   * live_images entry goes out as a video media item, the rest as
   * plain photo URLs (sendMediaGroup's default).
   */
  private buildCarouselMedia(
    images: string[],
    liveImages: string[],
  ): Array<string | { type: string; media: string }> {
    const items: Array<string | { type: string; media: string }> = [];
    images.forEach((url, index) => {
      if (liveImages[index]) {
        items.push({ type: 'video', media: liveImages[index] });
      } else {
        items.push(url);
      }
    });
    return items;
  }

  /**
   * Facebook: fetch via tool77, deliver the best combined-audio+video
   * format, with a 🎵 Download Audio button when a separate audio track
   * exists.
   */
  async handleFacebookUrl(chatId: number, userId: number, rawUrl: string): Promise<void> {
    let url = rawUrl;
    if (Validator.isShortLink(url)) {
      url = await Validator.resolveRedirect(url);
    }
    // tool77 only accepts the plain https://www.facebook.com/<type>/<id>
    // shape — strip tracking queries etc. before it sees the link.
    url = Validator.normalizeFacebookUrl(url);

    if (!(await this.forceJoin.checkAll(userId))) {
      await this.storePendingRequest(userId, chatId, url);
      await this.forceJoin.sendJoinPrompt(chatId);
      return;
    }

    await this.telegram.sendChatAction(chatId, 'typing');

    const data = await this.tool77.fetch(url);
    if (!data) {
      await this.telegram.sendMessage(
        chatId,
        "❌ Couldn't fetch that Facebook link. It may be private or invalid.",
      );
      return;
    }

    const title = Validator.markdownEscape(String(data.title ?? ''));
    const id = this.tool77.cacheId(url);

    const audio = this.tool77.getBestAudio(data);
    const keyboard: ReplyMarkup | null = audio?.url
      ? { inline_keyboard: [[{ text: '🎵 Download Audio', callback_data: 'dlaud_' + id }]] }
      : null;

    const video = this.tool77.getBestNormal(data);
    const videoUrl = video ? this.tool77.resolveUrl(video) : null;
    if (!videoUrl) {
      await this.telegram.sendMessage(chatId, '❌ No downloadable video found for that link.');
      return;
    }

    await this.deliverVideo(chatId, videoUrl, title, keyboard);
    await this.saveDownload(userId, String(data.originUrl ?? url), 'facebook_video');
    await this.stats.recordDownload();
    await this.ads.maybeShow(chatId);
  }

  /**
   * Sends a video by URL, or downloads-then-uploads for anything over
   * 20MB (Telegram's fetch-by-URL ceiling in practice).
   */
  private async deliverVideo(
    chatId: number,
    videoUrl: string,
    caption: string,
    keyboard: ReplyMarkup | null,
  ): Promise<void> {
    await this.telegram.sendChatAction(chatId, 'upload_video');
    const size = await Validator.getRemoteFileSize(videoUrl);

    if (size !== null && size > Config.get<number>('max_url_upload_bytes', 20 * 1024 * 1024)) {
      const localPath = await this.media.downloadToTemp(videoUrl);
      if (!localPath) {
        await this.telegram.sendMessage(chatId, '❌ Failed to process this video. Please try again.');
        return;
      }
      await this.telegram.sendVideoLocal(chatId, localPath, caption, keyboard);
      await this.media.cleanup(localPath);
    } else {
      await this.telegram.sendVideo(chatId, videoUrl, caption, keyboard);
    }
  }

  /**
   * YouTube: no media file is sent to the chat. The bot replies with
   * the video's cover image as a photo message carrying the download
   * menu (fallback: plain text message if there's no thumbnail):
   * inline buttons are short download links on this server (/dl
   * re-resolves the real CDN URL when tapped, so the user's browser
   * does the actual download): video buttons for each rung of
   * Tool77Service.MENU_HEIGHTS the video actually offers (1080p →
   * 360p), plus one audio button per format tool77 returned
   * (typically m4a + opus).
   */
  async handleYouTubeUrl(chatId: number, userId: number, rawUrl: string): Promise<void> {
    let url = rawUrl;
    if (Validator.isShortLink(url)) {
      url = await Validator.resolveRedirect(url);
    }

    if (!(await this.forceJoin.checkAll(userId))) {
      await this.storePendingRequest(userId, chatId, url);
      await this.forceJoin.sendJoinPrompt(chatId);
      return;
    }

    await this.telegram.sendChatAction(chatId, 'typing');

    const videoId = Validator.extractYouTubeId(url);
    const cleanUrl = videoId ? 'https://www.youtube.com/watch?v=' + videoId : url;

    const data = await this.tool77.fetch(cleanUrl);
    if (!data) {
      await this.telegram.sendMessage(chatId, "❌ Couldn't fetch that YouTube link. Please try again later.");
      return;
    }

    const keyboard = await this.buildYoutubeKeyboard(
      data,
      this.downloaderBaseUrl(),
      this.tool77.cacheId(cleanUrl),
    );
    if (!keyboard) {
      await this.telegram.sendMessage(chatId, '❌ No downloadable formats found for that video.');
      return;
    }

    const title = String(data.title ?? '').trim();
    const caption =
      (title !== '' ? `🎬 *${Validator.markdownEscape(mbSubstr(title, 0, 256))}*\n` : '') +
      'Pick a quality to download:';

    // Cover + buttons in one photo message; falls back to a plain text
    // menu when the video has no usable thumbnail or Telegram rejects
    // the URL (sendPhoto retries parse errors internally, anything else
    // lands here).
    let sent = false;
    const thumbnail = String(data.thumbnail ?? '');
    if (/^https?:\/\//i.test(thumbnail)) {
      await this.telegram.sendChatAction(chatId, 'upload_photo');
      const result = await this.telegram.sendPhoto(chatId, thumbnail, caption, keyboard);
      sent = result?.ok === true;
      if (!sent) {
        logger.write('warning', 'YouTube cover send failed — falling back to text menu', {
          chat_id: chatId,
          thumbnail,
          description: result?.description ?? null,
        });
      }
    }

    if (!sent) {
      await this.telegram.sendMessage(chatId, caption, keyboard);
    }

    await this.saveDownload(userId, cleanUrl, 'youtube_link');
    await this.stats.recordDownload();
    await this.ads.maybeShow(chatId);
  }

  /**
   * Public base URL of the web downloader — same host as the incoming
   * webhook request, falling back to the configured webhook URL's host
   * (CLI context has no Host header).
   */
  private downloaderBaseUrl(): string {
    let host = getRequestHost();
    if (!host) {
      const webhookUrl = Config.get<string>('webhook_url', '');
      try {
        host = webhookUrl ? new URL(webhookUrl).host : '';
      } catch {
        host = '';
      }
    }
    return host ? `https://${host}` : '';
  }

  /**
   * Inline-button keyboard over getVideoQualities() + getAudioFormats():
   * two buttons per row (Telegram renders these nicely at that width),
   * videos first then audios. Null when neither produced a single
   * resolvable link. Everything above 360p is a video-only stream, so
   * those buttons get a 🔇 marker.
   *
   * Buttons deliberately do NOT carry the resolved CDN URLs — each
   * googlevideo link is 800–1500 chars and a few of them blow past
   * Telegram's reply-markup size cap ("reply markup is too long"). They
   * carry a short /dl redirect link keyed by the tool77 cache id
   * instead; the server re-resolves the real URL at tap time. That also
   * makes button lifetime == tool77_cache_ttl.
   */
  private async buildYoutubeKeyboard(
    data: Record<string, any>,
    baseUrl: string,
    id: string,
  ): Promise<ReplyMarkup | null> {
    if (baseUrl === '' || id === '') {
      return null;
    }

    const videos = this.tool77.getVideoQualities(data);
    const audios = this.tool77.getAudioFormats(data);
    if (videos.size === 0 && Object.keys(audios).length === 0) {
      return null;
    }

    const rows: Array<Array<{ text: string; url: string }>> = [];
    let row: Array<{ text: string; url: string }> = [];

    for (const [height, video] of videos) {
      row.push({
        text: `🎬 ${height}p${video.hasAudio ? '' : ' 🔇'}`,
        url: `${baseUrl}/dl?id=${encodeURIComponent(id)}&kind=video&h=${Number(height)}`,
      });
      if (row.length === 2) {
        rows.push(row);
        row = [];
      }
    }
    for (const [ext, audio] of Object.entries(audios)) {
      const label = ext.toUpperCase() + (audio.kbps > 0 ? ` · ${audio.kbps}kbps` : '');
      row.push({
        text: `🎵 ${label}`,
        url: `${baseUrl}/dl?id=${encodeURIComponent(id)}&kind=audio&fmt=${encodeURIComponent(ext)}`,
      });
      if (row.length === 2) {
        rows.push(row);
        row = [];
      }
    }
    if (row.length > 0) {
      rows.push(row);
    }

    return { inline_keyboard: rows };
  }

  private async handleTextSearch(chatId: number, userId: number, query: string): Promise<void> {
    if (!(await this.forceJoin.checkAll(userId))) {
      await this.storePendingRequest(userId, chatId, query);
      await this.forceJoin.sendJoinPrompt(chatId);
      return;
    }

    await this.telegram.sendChatAction(chatId, 'typing');
    const results = await this.ytSearch.search(query, 10);

    if (results.items.length === 0) {
      await this.telegram.sendMessage(chatId, '😕 No results found for that search.');
      return;
    }

    await this.telegram.sendMessage(
      chatId,
      `🔍 Results for *${Validator.markdownEscape(query)}*:`,
      { inline_keyboard: await this.buildSearchKeyboard(results, query) },
    );
  }

  private async buildSearchKeyboard(
    results: SearchResult,
    query: string,
  ): Promise<Array<Array<{ text: string; callback_data: string }>>> {
    const keyboard: Array<Array<{ text: string; callback_data: string }>> = [];
    for (const item of results.items) {
      if (!item.videoId) {
        continue;
      }
      keyboard.push([
        { text: mbSubstr(item.title, 0, 60), callback_data: 'ytdl_' + item.videoId },
      ]);
    }
    if (results.nextPageToken) {
      keyboard.push([
        {
          text: '➡️ Next',
          callback_data:
            'nextpage_' + (await this.stashCallbackPayload({ q: query, pt: results.nextPageToken })),
        },
      ]);
    }
    return keyboard;
  }

  /**
   * Telegram caps callback_data at 64 bytes — far too small for a
   * search query plus YouTube's pageToken (and long TikTok usernames),
   * which is what the pagination buttons need to carry. So the payload
   * goes into the `cache` table under a random key and only the short
   * key rides in the button. If the DB write fails, falls back to
   * inline base64, which still works whenever the payload happens to
   * fit the cap.
   */
  private async stashCallbackPayload(payload: Record<string, unknown>): Promise<string> {
    try {
      const key = randomHex(8);
      await cacheSet('cbpayload_' + key, payload, Config.get<number>('cache_ttl', 3600));
      return key;
    } catch (error) {
      return Buffer.from(JSON.stringify(payload)).toString('base64');
    }
  }

  /** Inverse of stashCallbackPayload(); accepts legacy inline-base64 payloads too. */
  private async popCallbackPayload(data: string): Promise<Record<string, any> | null> {
    if (/^[0-9a-f]{16}$/.test(data)) {
      return cacheGet<Record<string, any>>('cbpayload_' + data);
    }
    try {
      const parsed = JSON.parse(Buffer.from(data, 'base64').toString('utf8'));
      return typeof parsed === 'object' && parsed !== null ? parsed : null;
    } catch {
      return null;
    }
  }

  /** /username @handle — lists a TikTok user's recent videos to pick from. */
  async handleUsernameLookup(chatId: number, userId: number, rawUsername: string): Promise<void> {
    const uniqueId = rawUsername.trim().replace(/^@+/, '');

    if (!(await this.forceJoin.checkAll(userId))) {
      await this.storePendingRequest(userId, chatId, '@' + uniqueId);
      await this.forceJoin.sendJoinPrompt(chatId);
      return;
    }

    await this.telegram.sendChatAction(chatId, 'typing');
    await this.deliverUsernameResults(chatId, null, uniqueId, 0);
  }

  /**
   * Shared by the initial lookup and the "Next" button — sends a new
   * message the first time, edits the existing one for pagination.
   */
  private async deliverUsernameResults(
    chatId: number,
    editMessageId: number | null,
    uniqueId: string,
    cursor: number,
  ): Promise<void> {
    const result = await this.tiktokUser.fetchPosts(uniqueId, 12, cursor);
    if (!result || result.videos.length === 0) {
      await this.telegram.sendMessage(
        chatId,
        `😕 No videos found for @${uniqueId} — the account may be private or not exist.`,
      );
      return;
    }

    const keyboard: Array<Array<{ text: string; callback_data: string }>> = [];
    for (const video of result.videos) {
      const videoId = String(video.video_id ?? video.id ?? '');
      if (videoId === '') {
        continue;
      }
      keyboard.push([{ text: this.buildVideoLabel(video), callback_data: 'tkuser_' + videoId }]);
    }

    if (result.hasMore) {
      keyboard.push([
        {
          text: '➡️ Next',
          callback_data:
            'tkusernext_' + (await this.stashCallbackPayload({ u: uniqueId, c: result.cursor })),
        },
      ]);
    }

    const text = `🎬 Recent videos from *@${Validator.markdownEscape(uniqueId)}*:`;
    if (editMessageId) {
      await this.telegram.editMessageText(chatId, editMessageId, text, { inline_keyboard: keyboard });
    } else {
      await this.telegram.sendMessage(chatId, text, { inline_keyboard: keyboard });
    }
  }

  private buildVideoLabel(video: Record<string, any>): string {
    const title = String(video.title ?? '').trim();
    let label = title !== '' ? mbSubstr(title, 0, 45) : 'Video';

    const plays = Number(video.play_count ?? 0);
    if (plays > 0) {
      label += ' · ' + this.formatCount(plays) + ' plays';
    }
    return label;
  }

  private formatCount(n: number): string {
    if (n >= 1_000_000) return String(Math.round((n / 1_000_000) * 10) / 10) + 'M';
    if (n >= 1_000) return String(Math.round((n / 1_000) * 10) / 10) + 'K';
    return String(n);
  }

  async handleCallback(callback: CallbackQuery): Promise<void> {
    const callbackId = callback.id;
    const data = callback.data ?? '';
    const chatId = callback.message?.chat?.id ?? null;
    const messageId = callback.message?.message_id ?? null;
    const userId = Number(callback.from?.id ?? 0);

    if (!chatId || !userId) {
      await this.telegram.answerCallbackQuery(callbackId);
      return;
    }

    if (data === 'check_join') {
      await this.onCheckJoin(callbackId, Number(chatId), Number(messageId), userId);
      return;
    }
    if (data.startsWith('nextpage_')) {
      await this.onNextPage(callbackId, Number(chatId), Number(messageId), data.slice(9));
      return;
    }
    if (data.startsWith('ytdl_')) {
      await this.onYtDl(callbackId, Number(chatId), Number(messageId), userId, data.slice(5));
      return;
    }
    if (data.startsWith('dlaud_')) {
      await this.onDownloadAudioButton(callbackId, Number(chatId), userId, data.slice(6));
      return;
    }
    if (data.startsWith('tkaud_')) {
      await this.onTikTokAudioButton(callbackId, Number(chatId), userId, data.slice(6));
      return;
    }
    if (data.startsWith('tkusernext_')) {
      await this.onTikTokUserNextPage(callbackId, Number(chatId), Number(messageId), data.slice(11));
      return;
    }
    if (data.startsWith('tkuser_')) {
      await this.onTikTokUserVideoSelected(
        callbackId,
        Number(chatId),
        Number(messageId),
        userId,
        data.slice(7),
      );
      return;
    }

    await this.telegram.answerCallbackQuery(callbackId);
  }

  private async onCheckJoin(
    callbackId: string,
    chatId: number,
    messageId: number,
    userId: number,
  ): Promise<void> {
    // The not-joined alert must be the FIRST (and only) answer to this
    // callback query — Telegram rejects any second
    // answerCallbackQuery on an already-answered query ID, so
    // answering unconditionally up front would silently swallow it.
    if (!(await this.forceJoin.checkAll(userId))) {
      await this.telegram.answerCallbackQuery(callbackId, "You haven't joined all channels yet.", true);
      return;
    }
    await this.telegram.answerCallbackQuery(callbackId);
    await this.telegram.deleteMessage(chatId, messageId);
    await this.resumePendingRequest(userId, chatId);
  }

  private async onNextPage(
    callbackId: string,
    chatId: number,
    messageId: number,
    encodedPayload: string,
  ): Promise<void> {
    await this.telegram.answerCallbackQuery(callbackId);
    const payload = await this.popCallbackPayload(encodedPayload);
    if (!payload || !payload.q) {
      return;
    }

    const results = await this.ytSearch.search(payload.q, 10, payload.pt ?? null);
    await this.telegram.editMessageText(
      chatId,
      messageId,
      `🔍 Results for *${Validator.markdownEscape(payload.q)}*:`,
      { inline_keyboard: await this.buildSearchKeyboard(results, payload.q) },
    );
  }

  private async onYtDl(
    callbackId: string,
    chatId: number,
    messageId: number,
    userId: number,
    videoId: string,
  ): Promise<void> {
    await this.telegram.answerCallbackQuery(callbackId);
    await this.telegram.editMessageReplyMarkup(chatId, messageId, null);
    await this.handleYouTubeUrl(chatId, userId, 'https://www.youtube.com/watch?v=' + videoId);
  }

  private async onTikTokUserNextPage(
    callbackId: string,
    chatId: number,
    messageId: number,
    encodedPayload: string,
  ): Promise<void> {
    await this.telegram.answerCallbackQuery(callbackId);
    const payload = await this.popCallbackPayload(encodedPayload);
    if (!payload || !payload.u) {
      return;
    }
    const cursorRaw = Number(payload.c ?? 0);
    const cursor = Number.isFinite(cursorRaw) ? cursorRaw : 0;
    await this.deliverUsernameResults(chatId, messageId, String(payload.u), cursor);
  }

  /** Reconstructs the canonical TikTok URL and hands off to handleTikTokUrl() — same delivery pipeline as any other TikTok link. */
  private async onTikTokUserVideoSelected(
    callbackId: string,
    chatId: number,
    messageId: number,
    userId: number,
    videoId: string,
  ): Promise<void> {
    const video = await this.tiktokUser.getCachedVideo(videoId);
    const uniqueId = String(video?.author?.unique_id ?? '');
    if (!video || uniqueId === '') {
      await this.telegram.answerCallbackQuery(callbackId, 'That expired — try /username again.', true);
      return;
    }

    await this.telegram.answerCallbackQuery(callbackId);
    await this.telegram.editMessageReplyMarkup(chatId, messageId, null);
    await this.handleTikTokUrl(chatId, userId, `https://www.tiktok.com/@${uniqueId}/video/${videoId}`);
  }

  /** "Download Audio" button under Facebook results — cached via Tool77Service. */
  private async onDownloadAudioButton(
    callbackId: string,
    chatId: number,
    userId: number,
    id: string,
  ): Promise<void> {
    const data = await this.tool77.getCachedById(id);
    if (!data) {
      await this.telegram.answerCallbackQuery(callbackId, 'That link expired — please resend it.', true);
      return;
    }

    const audio = this.tool77.getBestAudio(data);
    const audioUrl = audio ? this.tool77.resolveUrl(audio) : null;
    if (!audioUrl) {
      await this.telegram.answerCallbackQuery(callbackId, 'No audio track available.', true);
      return;
    }

    await this.telegram.answerCallbackQuery(callbackId);
    await this.telegram.sendChatAction(chatId, 'upload_audio');
    await this.telegram.sendAudio(chatId, audioUrl, '🎵 Extracted audio');

    await this.saveDownload(userId, String(data.originUrl ?? id), 'facebook_audio');
    await this.stats.recordDownload();
    await this.ads.maybeShow(chatId);
  }

  /** "Download Audio" button under TikTok results — audio URL pre-cached by handleTikTokUrl() via TikwmService. */
  private async onTikTokAudioButton(
    callbackId: string,
    chatId: number,
    userId: number,
    tiktokId: string,
  ): Promise<void> {
    const cached = await this.tikwm.getCachedAudio(tiktokId);
    if (!cached?.url) {
      await this.telegram.answerCallbackQuery(callbackId, 'That link expired — please resend it.', true);
      return;
    }

    await this.telegram.answerCallbackQuery(callbackId);
    await this.telegram.sendChatAction(chatId, 'upload_audio');
    await this.telegram.sendAudio(chatId, cached.url, '🎵 Extracted audio');

    await this.saveDownload(userId, String(cached.origin ?? 'tkaud_' + tiktokId), 'tiktok_audio');
    await this.stats.recordDownload();
    await this.ads.maybeShow(chatId);
  }

  private async storePendingRequest(userId: number, chatId: number, url: string): Promise<void> {
    unwrap(
      await db()
        .from('pending_requests')
        .upsert({ user_id: userId, chat_id: chatId, url }, { onConflict: 'user_id' }),
      'storePendingRequest',
    );
  }

  private async resumePendingRequest(userId: number, chatId: number): Promise<void> {
    const rows = unwrap(
      await db().from('pending_requests').select('*').eq('user_id', userId).limit(1),
      'resumePendingRequest',
    );
    if (rows.length === 0) {
      return;
    }

    unwrap(await db().from('pending_requests').delete().eq('user_id', userId), 'resumePendingRequest');

    const url = rows[0].url as string;
    if (Validator.isTikTokUrl(url)) {
      await this.handleTikTokUrl(chatId, userId, url);
    } else if (Validator.isFacebookUrl(url)) {
      await this.handleFacebookUrl(chatId, userId, url);
    } else if (Validator.isYouTubeUrl(url)) {
      await this.handleYouTubeUrl(chatId, userId, url);
    } else if (url.startsWith('@')) {
      await this.handleUsernameLookup(chatId, userId, url);
    } else {
      await this.handleTextSearch(chatId, userId, url);
    }
  }

  private async saveDownload(userId: number, url: string, type: string): Promise<void> {
    await Download.record(userId, url, type);
  }
}

function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

function md5(text: string): string {
  return crypto.createHash('md5').update(text).digest('hex');
}

function randomHex(bytes: number): string {
  return crypto.randomBytes(bytes).toString('hex');
}
