import fs from 'node:fs';
import { Config } from '../config.js';
import { allRows, db, unwrap } from '../core/db.js';
import { logger } from '../helpers/logger.js';
import { formatTimestamp, mbSubstr } from '../helpers/text.js';
import { Validator } from '../helpers/validator.js';
import { Ad } from '../models/ad.js';
import { Log } from '../models/log.js';
import { PendingBroadcast } from '../models/pendingBroadcast.js';
import { Setting } from '../models/setting.js';
import { User } from '../models/user.js';
import { BroadcastService } from '../services/broadcast.js';
import { StatisticsService } from '../services/statistics.js';
import { TelegramService } from '../services/telegram.js';

/** Tables database/schema.postgres.sql is expected to have created. */
const EXPECTED_TABLES = [
  'users',
  'admins',
  'downloads',
  'required_channels',
  'settings',
  'statistics',
  'logs',
  'cache',
  'pending_requests',
  'youtube_downloads',
  'ads',
  'pending_broadcasts',
];

/**
 * Admin-only commands, dispatched from BotController.handleCommand()
 * after User.isAdmin() passes. Adding an ad is NOT a command here —
 * an admin forwards any message to the bot and BotController stores it
 * automatically (see BotController.handleAdForward()).
 */
export class AdminController {
  private readonly telegram = new TelegramService();

  async handleCommand(text: string, chatId: number, adminId: number): Promise<void> {
    const parts = text.trim().split(/\s+/);
    const command = (parts.shift() ?? '').toLowerCase();

    switch (command) {
      case '/admin':
        await this.dashboard(chatId);
        break;
      case '/users':
        await this.listUsers(chatId);
        break;
      case '/ban':
        await this.banUser(chatId, parts, true);
        break;
      case '/unban':
        await this.banUser(chatId, parts, false);
        break;
      case '/history':
        await this.history(chatId, parts);
        break;
      case '/list':
        await this.listDownloads(chatId, parts);
        break;
      case '/forcejoin':
        await this.toggleForceJoin(chatId, parts);
        break;
      case '/addchannel':
        await this.addChannel(chatId, parts);
        break;
      case '/removechannel':
        await this.removeChannel(chatId, parts);
        break;
      case '/channels':
        await this.listChannels(chatId);
        break;
      case '/broadcast':
        await this.broadcast(chatId, parts);
        break;
      case '/forward':
        await this.startForwardBroadcast(chatId, adminId);
        break;
      case '/stats':
        await this.statsCommand(chatId);
        break;
      case '/logs':
        await this.logsCommand(chatId);
        break;
      case '/maintenance':
        await this.toggleMaintenance(chatId, parts);
        break;
      case '/addadmin':
        await this.addAdmin(chatId, parts);
        break;
      case '/removeadmin':
        await this.removeAdmin(chatId, parts);
        break;
      case '/admins':
        await this.listAdmins(chatId);
        break;
      case '/ads':
        await this.toggleAds(chatId, parts);
        break;
      case '/adslist':
        await this.listAds(chatId);
        break;
      case '/adsremove':
        await this.removeAd(chatId, parts);
        break;
      case '/top':
        await this.topUsers(chatId);
        break;
      case '/errors':
        await this.errorsCommand(chatId);
        break;
      case '/setup':
        await this.setupDatabase(chatId);
        break;
      default:
        await this.telegram.sendMessage(
          chatId,
          'Unknown admin command. Try /admin for the dashboard.',
        );
    }
  }

  private async dashboard(chatId: number): Promise<void> {
    const stats = new StatisticsService();
    const text =
      '🛠 *Admin Dashboard*\n\n' +
      `👥 Users: ${await stats.totalUsers()}\n` +
      `⬇️ Downloads: ${await stats.totalDownloads()}\n` +
      `📢 Ads stored: ${await Ad.count()}\n` +
      `🔒 Force join: ${(await Setting.isTrue('force_join_enabled')) ? 'ON' : 'OFF'}\n` +
      `📣 Ads: ${(await Setting.isTrue('ads_enabled')) ? 'ON' : 'OFF'}\n` +
      `🚧 Maintenance: ${(await Setting.isTrue('maintenance_mode')) ? 'ON' : 'OFF'}\n\n` +
      '/users /ban /unban /history /list /top\n' +
      '/forcejoin /addchannel /removechannel /channels\n' +
      '/broadcast /forward /stats /logs /errors\n' +
      '/addadmin /removeadmin /admins\n' +
      '/ads /adslist /adsremove — forward any message to add one\n' +
      '/maintenance /setup';
    await this.telegram.sendMessage(chatId, text);
  }

  private async listUsers(chatId: number): Promise<void> {
    const rows = unwrap(
      await db()
        .from('users')
        .select('telegram_id, username, is_banned')
        .order('id', { ascending: false })
        .limit(30),
      'AdminController.listUsers',
    );
    const lines = rows.map(
      (row) =>
        `${row.is_banned ? '🚫' : '✅'} \`${row.telegram_id}\` @` +
        Validator.markdownEscape(String(row.username || '-')),
    );
    await this.telegram.sendMessage(
      chatId,
      '*Recent users (max 30):*\n' + (lines.length > 0 ? lines.join('\n') : 'No users yet.'),
    );
  }

  private async banUser(chatId: number, args: string[], ban: boolean): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, `Usage: /${ban ? 'ban' : 'unban'} <telegram_id>`);
      return;
    }
    await User.setBanned(Number(args[0]), ban);
    await this.telegram.sendMessage(chatId, `${ban ? '🚫 Banned' : '✅ Unbanned'} user \`${args[0]}\`.`);
  }

  private async history(chatId: number, args: string[]): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, 'Usage: /history <telegram_id>');
      return;
    }
    const rows = unwrap(
      await db()
        .from('downloads')
        .select('url, type, created_at')
        .eq('user_id', Number(args[0]))
        .order('id', { ascending: false })
        .limit(15),
      'AdminController.history',
    );
    const lines = rows.map((row) => `[${row.type}] ${formatTimestamp(row.created_at)}`);
    await this.telegram.sendMessage(
      chatId,
      `*Download history for \`${args[0]}\` (max 15):*\n` +
        (lines.length > 0 ? lines.join('\n') : 'No downloads yet.'),
    );
  }

  /** Like /history, but shows the actual links instead of just type + timestamp. */
  private async listDownloads(chatId: number, args: string[]): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, 'Usage: /list <telegram_id>');
      return;
    }

    const rows = unwrap(
      await db()
        .from('downloads')
        .select('url, type, created_at')
        .eq('user_id', Number(args[0]))
        .order('id', { ascending: false })
        .limit(20),
      'AdminController.listDownloads',
    );

    if (rows.length === 0) {
      await this.telegram.sendMessage(chatId, `No downloads yet for \`${args[0]}\`.`);
      return;
    }

    const lines = rows.map((row) => {
      const url = String(row.url);
      const short = url.length > 70 ? mbSubstr(url, 0, 70) + '…' : url;
      // Raw URLs routinely contain _ * ` — Telegram's legacy Markdown
      // treats those as formatting characters, so an unescaped link
      // here can silently mangle the message or make Telegram reject it
      // outright as unparseable.
      return `[${row.type}] ` + Validator.markdownEscape(short);
    });

    await this.telegram.sendMessage(
      chatId,
      `*Downloaded links for \`${args[0]}\` (max 20):*\n` + lines.join('\n'),
    );
  }

  private async toggleForceJoin(chatId: number, args: string[]): Promise<void> {
    const state = (args[0] ?? '').toLowerCase();
    if (state !== 'on' && state !== 'off') {
      await this.telegram.sendMessage(chatId, 'Usage: /forcejoin on|off');
      return;
    }
    await Setting.set('force_join_enabled', state === 'on' ? '1' : '0');
    await this.telegram.sendMessage(chatId, `Force join turned ${state.toUpperCase()}.`);
  }

  private async addChannel(chatId: number, args: string[]): Promise<void> {
    if (!args[0]) {
      await this.telegram.sendMessage(chatId, 'Usage: /addchannel @channelusername [Optional Title]');
      return;
    }
    const username = '@' + args[0].replace(/^@+/, '');
    const title = args.slice(1).join(' ') || null;

    unwrap(
      await db().from('required_channels').insert({ channel_username: username, channel_title: title }),
      'AdminController.addChannel',
    );
    await this.telegram.sendMessage(
      chatId,
      `✅ Added channel ${username}.\n\nMake sure the bot is an admin in that channel so it can check membership.`,
    );
  }

  private async removeChannel(chatId: number, args: string[]): Promise<void> {
    if (!args[0]) {
      await this.telegram.sendMessage(chatId, 'Usage: /removechannel @channelusername');
      return;
    }
    const username = '@' + args[0].replace(/^@+/, '');
    unwrap(
      await db().from('required_channels').delete().eq('channel_username', username),
      'AdminController.removeChannel',
    );
    await this.telegram.sendMessage(chatId, `🗑 Removed channel ${username}.`);
  }

  private async listChannels(chatId: number): Promise<void> {
    const rows = await allRows<{ channel_username: string; channel_title: string | null }>(
      (from, to) =>
        db()
          .from('required_channels')
          .select('channel_username, channel_title')
          .order('id', { ascending: true })
          .range(from, to),
      'AdminController.listChannels',
    );
    const lines = rows.map(
      (row) =>
        Validator.markdownEscape(String(row.channel_username)) +
        (row.channel_title ? ' (' + Validator.markdownEscape(String(row.channel_title)) + ')' : ''),
    );
    await this.telegram.sendMessage(
      chatId,
      '*Required channels:*\n' + (lines.length > 0 ? lines.join('\n') : 'None set.'),
    );
  }

  private async broadcast(chatId: number, args: string[]): Promise<void> {
    const message = args.join(' ');
    if (message === '') {
      await this.telegram.sendMessage(chatId, 'Usage: /broadcast <message>');
      return;
    }
    await this.telegram.sendMessage(chatId, '📣 Broadcasting to all active users…');
    const result = await new BroadcastService(this.telegram).send(message);
    await this.telegram.sendMessage(
      chatId,
      `✅ Sent to ${result.success}/${result.total} users (${result.failed} failed).`,
    );
  }

  /**
   * Arms the admin for /forward — the actual broadcast happens when
   * BotController sees their next forwarded message (see
   * PendingBroadcast and BotController.handleBroadcastForward()). A
   * slash command can't carry a forward in the same update, so this
   * two-step is what lets /forward broadcast any content type (photo,
   * video, poll, whatever) instead of /broadcast's plain text only.
   */
  private async startForwardBroadcast(chatId: number, adminId: number): Promise<void> {
    await PendingBroadcast.set(adminId);
    await this.telegram.sendMessage(
      chatId,
      '📤 Now forward me the message you want to broadcast to all users. (Expires in 10 minutes.)',
    );
  }

  private async statsCommand(chatId: number): Promise<void> {
    const days = await new StatisticsService().getLastDays(7);
    const lines = days.map(
      (row) =>
        `${formatTimestamp(row.stat_date).slice(0, 10)}: ${row.downloads_count} downloads, ${row.new_users_count} new users`,
    );
    await this.telegram.sendMessage(
      chatId,
      '*Last 7 days:*\n' + (lines.length > 0 ? lines.join('\n') : 'No data yet.'),
    );
  }

  /**
   * Reads the tail of the flat file log (config 'log_file') instead of
   * the `logs` table: the file gets every entry unconditionally, while
   * the table write silently no-ops whenever the DB is down or
   * mid-setup, so file-backed /logs stays useful exactly when things
   * are going wrong.
   */
  private async logsCommand(chatId: number): Promise<void> {
    const logFile = Config.get<string>('log_file', '');
    let content = '';
    if (logFile !== '') {
      try {
        content = fs.readFileSync(logFile, 'utf8');
      } catch {
        content = '';
      }
    }

    if (content === '') {
      await this.telegram.sendMessage(
        chatId,
        `📄 No log file found at \`${logFile}\` yet — nothing has been logged.`,
      );
      return;
    }

    const allLines = content
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line !== '');
    const tail = allLines.slice(-15);

    // Raw lines mix timestamps, levels, messages and JSON context, all
    // full of _ * [ characters Telegram's Markdown would eat — escape
    // each line whole rather than trying to reformat them.
    const lines = tail.map((line) => Validator.markdownEscape(mbSubstr(line, 0, 200)));

    await this.telegram.sendMessage(
      chatId,
      '*Recent logs (max 15 from app.log):*\n' + (lines.length > 0 ? lines.join('\n') : 'Log file is empty.'),
    );
  }

  private async errorsCommand(chatId: number): Promise<void> {
    const rows = await Log.recentByLevel('error', 15);
    const lines = rows.map(
      (row) =>
        `${formatTimestamp(row.created_at)}: ` +
        Validator.markdownEscape(mbSubstr(String(row.message), 0, 100)),
    );
    await this.telegram.sendMessage(
      chatId,
      '*Recent errors (max 15):*\n' + (lines.length > 0 ? lines.join('\n') : 'No errors logged. 🎉'),
    );
  }

  private async toggleMaintenance(chatId: number, args: string[]): Promise<void> {
    const state = (args[0] ?? '').toLowerCase();
    if (state !== 'on' && state !== 'off') {
      await this.telegram.sendMessage(chatId, 'Usage: /maintenance on|off');
      return;
    }
    await Setting.set('maintenance_mode', state === 'on' ? '1' : '0');
    await this.telegram.sendMessage(chatId, `Maintenance mode turned ${state.toUpperCase()}.`);
  }

  private async addAdmin(chatId: number, args: string[]): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, 'Usage: /addadmin <telegram_id>');
      return;
    }
    await User.addAdmin(Number(args[0]));
    await this.telegram.sendMessage(chatId, `✅ \`${args[0]}\` can now use admin commands.`);
  }

  private async removeAdmin(chatId: number, args: string[]): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, 'Usage: /removeadmin <telegram_id>');
      return;
    }
    const removed = await User.removeAdmin(Number(args[0]));
    await this.telegram.sendMessage(
      chatId,
      removed
        ? `🗑 Removed \`${args[0]}\` from admins.`
        : "That ID wasn't in the admins table (the bootstrap admin_telegram_id can't be removed this way).",
    );
  }

  private async listAdmins(chatId: number): Promise<void> {
    const rows = await User.listAdmins();
    const lines = rows.map((row) => `\`${row.telegram_id}\` — added ${formatTimestamp(row.added_at)}`);
    await this.telegram.sendMessage(
      chatId,
      '*Admins (from the admins table):*\n' +
        (lines.length > 0 ? lines.join('\n') : 'None added yet.') +
        '\n\nThe bootstrap admin_telegram_id from .env always has access too, even if not listed here.',
    );
  }

  private async toggleAds(chatId: number, args: string[]): Promise<void> {
    const state = (args[0] ?? '').toLowerCase();
    if (state !== 'on' && state !== 'off') {
      await this.telegram.sendMessage(
        chatId,
        'Usage: /ads on|off\n\nTo add an ad, just forward any message to the bot.',
      );
      return;
    }
    await Setting.set('ads_enabled', state === 'on' ? '1' : '0');
    await this.telegram.sendMessage(chatId, `Ads turned ${state.toUpperCase()}.`);
  }

  private async listAds(chatId: number): Promise<void> {
    const rows = await Ad.all();
    const lines = rows.map((row) => `#${row.id} — added ${formatTimestamp(row.created_at)}`);
    await this.telegram.sendMessage(
      chatId,
      `*Stored ads (${rows.length}):*\n` +
        (lines.length > 0 ? lines.join('\n') : 'None yet — forward a message to add one.'),
    );
  }

  private async removeAd(chatId: number, args: string[]): Promise<void> {
    if (!args[0] || !/^\d+$/.test(args[0])) {
      await this.telegram.sendMessage(chatId, 'Usage: /adsremove <id> — see IDs with /adslist');
      return;
    }
    const removed = await Ad.delete(Number(args[0]));
    await this.telegram.sendMessage(
      chatId,
      removed ? `🗑 Removed ad #${args[0]}.` : 'No ad with that ID.',
    );
  }

  /**
   * Reports whether the Supabase schema has been applied. The schema
   * itself runs out-of-band (Supabase's SQL editor / `psql` — see
   * database/schema.postgres.sql) because PostgREST deliberately has
   * no way to execute DDL, so /setup verifies rather than applies.
   */
  private async setupDatabase(chatId: number): Promise<void> {
    const lines: string[] = [];

    for (const table of EXPECTED_TABLES) {
      try {
        const result = await db().from(table).select('*', { count: 'exact', head: true });
        lines.push(result.error ? `❌ ${table}: ${escapeSnippet(result.error.message)}` : `✅ ${table}`);
      } catch (error) {
        lines.push(`❌ ${table}: ${escapeSnippet(error instanceof Error ? error.message : String(error))}`);
      }
    }

    const missing = lines.filter((line) => line.startsWith('❌')).length;
    const footer =
      missing === 0
        ? '\nAll tables present — the two RPC functions (`stat_bump`, `top_downloaders`) ship in the same schema file.'
        : '\nApply `database/schema.postgres.sql` in the Supabase SQL editor to create whatever is missing.';

    await this.telegram.sendMessage(chatId, `🛠 *Database check*\n${lines.join('\n')}${footer}`);
  }

  private async topUsers(chatId: number): Promise<void> {
    const rows = await new StatisticsService().topUsers(10);
    const lines = rows.map(
      (row) =>
        `${row.downloads}× — \`${row.telegram_id}\` @` +
        Validator.markdownEscape(String(row.username || '-')),
    );
    await this.telegram.sendMessage(
      chatId,
      '*Top downloaders:*\n' + (lines.length > 0 ? lines.join('\n') : 'No downloads yet.'),
    );
  }
}

function escapeSnippet(text: string): string {
  return Validator.markdownEscape(mbSubstr(text.replace(/\s+/g, ' '), 0, 100));
}
