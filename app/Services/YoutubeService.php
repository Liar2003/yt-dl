<?php

namespace App\Services;

use App\Helpers\Logger;

/**
 * YouTube -> audio conversion via the Savenow API.
 *
 * IMPORTANT: Savenow is an unofficial, undocumented third-party API.
 * The endpoint paths and response shape below (progress_url,
 * `progress` reaching 1000, download_url/thumbnail_url on completion)
 * follow the pattern used by the "savenow.to"-style converters
 * referenced in the spec, but there is no stable public API
 * reference to verify field names against. Treat SAVENOW_INITIATE_URL
 * / SAVENOW_PROGRESS_URL and the field names in curlGet()'s callers
 * as the one thing to double check against the real API (e.g. via
 * your browser's network tab while using the site) before relying on
 * this in production — a captcha, changed field name, or a moved
 * endpoint will make initiateDownload()/checkProgress() return null
 * without any other code changes being needed.
 *
 * This service deliberately has no sleep-and-poll-in-a-loop method.
 * checkProgress() is a single bounded HTTP call; whoever calls it
 * decides how many times and how often. Two callers do that today:
 *   - BotController::handleYouTubeUrl() — one fast check right after
 *     initiateDownload(), for conversions that finish almost instantly
 *   - bin/poll-single.php — a detached background process, spawned
 *     on demand (not by cron) per job, that keeps checking until it's
 *     ready
 * Neither ties up a PHP-FPM worker waiting on Savenow — see
 * bin/poll-single.php's docblock for why that matters on shared
 * hosting under concurrent load.
 */
class YoutubeService
{
    private const SAVENOW_INITIATE_URL = 'https://api.savenow.to/v1/init';
    private const SAVENOW_PROGRESS_URL = 'https://api.savenow.to/v1/progress';

    /** Kicks off a conversion; returns ['progress_url' => ..., 'title' => ...] or null. */
    public function initiateDownload(string $url): ?array
    {
        $response = $this->curlGet(self::SAVENOW_INITIATE_URL, ['url' => $url, 'format' => 'mp3']);
        if (!$response || empty($response['progress_url'])) {
            Logger::write('warning', 'YouTube initiateDownload failed', ['url' => $url, 'response' => $response]);
            return null;
        }
        return [
            'progress_url' => $response['progress_url'],
            'title'        => $response['title'] ?? null,
        ];
    }

    /**
     * A single poll — one bounded HTTP call, never a loop. Returns
     * ['ready' => bool, ...] on a reachable server, or null if the
     * request itself failed. $timeoutSeconds is kept short (default 8)
     * for the request-time caller in BotController, since that call
     * happens inline in a webhook response; bin/poll-youtube.php can
     * afford to pass a longer one since it runs outside any request.
     */
    public function checkProgress(string $progressUrl, int $timeoutSeconds = 8): ?array
    {
        $response = $this->curlGet($progressUrl, [], $timeoutSeconds);
        if (!$response) {
            return null;
        }
        if ((int) ($response['progress'] ?? 0) >= 1000) {
            return [
                'ready'          => true,
                'download_url'   => $response['download_url'] ?? null,
                'title'          => $response['title'] ?? null,
                'thumbnail_url'  => $response['thumbnail_url'] ?? null,
            ];
        }
        return ['ready' => false];
    }

    private function curlGet(string $url, array $query = [], int $timeoutSeconds = 15): ?array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'YouTube service cURL error: ' . curl_error($ch), ['url' => $url]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
