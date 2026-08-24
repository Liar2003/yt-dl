<?php
/**
 * Background poller for ONE YouTube conversion job. Not a cron job —
 * this is spawned on demand, once per job, by
 * BotController::spawnBackgroundPoller() right when a YouTube link
 * comes in and isn't ready within the first fast check. It runs as
 * its own detached OS process, entirely outside the PHP-FPM worker
 * pool, so however long it polls for, it can never block a Telegram
 * webhook request or eat into the pool other users' requests share.
 *
 * Usage: php bin/poll-single.php <youtube_downloads.id>
 * (You should not need to run this by hand — BotController does it
 * automatically. It's safe to run manually for debugging, though.)
 */

require_once __DIR__ . '/../app/autoload.php';

use App\Helpers\Logger;
use App\Helpers\Validator;
use App\Models\Download;
use App\Models\YoutubeDownload;
use App\Services\StatisticsService;
use App\Services\TelegramService;
use App\Services\YoutubeService;

const POLL_INTERVAL_SECONDS = 3;
const MAX_POLL_SECONDS = 600; // give up after 10 minutes of polling this one job

$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php poll-single.php <job_id>" . PHP_EOL);
    exit(1);
}

$youtube = new YoutubeService();
$telegram = new TelegramService();
$stats = new StatisticsService();

$elapsed = 0;
while ($elapsed < MAX_POLL_SECONDS) {
    $row = YoutubeDownload::find($jobId);
    if (!$row) {
        // Someone already delivered it (most likely a "Check Progress" tap) — nothing left to do.
        exit(0);
    }

    $status = $youtube->checkProgress($row['progress_url'], 15);

    if ($status && $status['ready'] && $status['download_url']) {
        // A "Check Progress" tap could be delivering this at the exact
        // same moment — claim() ensures only one of us actually sends it.
        if (!YoutubeDownload::claim($jobId)) {
            exit(0);
        }

        $title = Validator::markdownEscape($status['title'] ?? $row['title'] ?? '');
        $telegram->sendChatAction((int) $row['chat_id'], 'upload_audio');
        $telegram->sendAudio((int) $row['chat_id'], $status['download_url'], $title, $status['thumbnail_url'] ?? null);

        YoutubeDownload::delete($jobId);
        Download::record((int) $row['user_id'], $row['progress_url'], 'youtube_audio');
        $stats->recordDownload();

        Logger::write('info', 'Background poller delivered a YouTube conversion', ['id' => $jobId]);
        exit(0);
    }

    sleep(POLL_INTERVAL_SECONDS);
    $elapsed += POLL_INTERVAL_SECONDS;
}

// Gave up — the row is left in place (still 'pending'), so the
// "Check Progress" button keeps working even after this process exits.
Logger::write('warning', 'Background poller gave up waiting on a YouTube conversion', ['id' => $jobId]);
