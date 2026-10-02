/**
 * Background poller for ONE YouTube conversion job. Not a cron job —
 * this is spawned on demand, once per job, whenever a YouTube link
 * isn't ready within the first fast check. It runs as its own process,
 * so however long it polls for, it can never block a Telegram webhook
 * request.
 *
 * Usage: bun run poll-single <youtube_downloads.id>
 * (You should not need to run this by hand — safe to run manually for
 * debugging, though.)
 */
import { logger } from '../src/helpers/logger.js';
import { Validator } from '../src/helpers/validator.js';
import { Download } from '../src/models/download.js';
import { YoutubeDownload } from '../src/models/youtubeDownload.js';
import { StatisticsService } from '../src/services/statistics.js';
import { TelegramService } from '../src/services/telegram.js';
import { YoutubeService } from '../src/services/youtube.js';

const POLL_INTERVAL_SECONDS = 3;
const MAX_POLL_SECONDS = 600; // give up after 10 minutes of polling this one job

const jobId = Number.parseInt(process.argv[2] ?? '0', 10) || 0;
if (jobId <= 0) {
  console.error('Usage: bun run poll-single <job_id>');
  process.exit(1);
}

const youtube = new YoutubeService();
const telegram = new TelegramService();
const stats = new StatisticsService();

let elapsed = 0;
while (elapsed < MAX_POLL_SECONDS) {
  const row = await YoutubeDownload.find(jobId);
  if (!row) {
    // Someone already delivered it (most likely a "Check Progress" tap) — nothing left to do.
    await logger.flush();
    process.exit(0);
  }

  const status = await youtube.checkProgress(row.progress_url, 15);

  if (status?.ready && status.download_url) {
    // A "Check Progress" tap could be delivering this at the exact same
    // moment — claim() ensures only one of us actually sends it.
    if (!(await YoutubeDownload.claim(jobId))) {
      await logger.flush();
    process.exit(0);
    }

    const title = Validator.markdownEscape(String(status.title ?? row.title ?? ''));
    await telegram.sendChatAction(Number(row.chat_id), 'upload_audio');
    await telegram.sendAudio(Number(row.chat_id), status.download_url, title, status.thumbnail_url ?? null);

    await YoutubeDownload.remove(jobId);
    await Download.record(Number(row.user_id), row.progress_url, 'youtube_audio');
    await stats.recordDownload();

    logger.write('info', 'Background poller delivered a YouTube conversion', { id: jobId });
    await logger.flush();
    process.exit(0);
  }

  await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_SECONDS * 1000));
  elapsed += POLL_INTERVAL_SECONDS;
}

// Gave up — the row is left in place (still 'pending'), so the
// "Check Progress" button keeps working even after this process exits.
logger.write('warning', 'Background poller gave up waiting on a YouTube conversion', { id: jobId });
await logger.flush();
