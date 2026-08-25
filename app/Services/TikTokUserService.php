<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Logger;
use Throwable;

/**
 * TikWM's user/posts endpoint — lists a TikTok user's recent videos by
 * @username. Separate from Tool77Service on purpose: this is a
 * "browse and pick" discovery feature, not a single-link download, and
 * TikWM is the one that offers it (tool77's endpoint only takes a
 * single video URL, not a username).
 *
 * Delivery is NOT handled here. Once a video is picked from the list,
 * BotController reconstructs its canonical TikTok URL and hands off to
 * the existing handleTikTokUrl() — same TikwmService-backed pipeline
 * as any other TikTok link, so the audio button, large-file fallback,
 * etc. all apply automatically instead of being duplicated here.
 */
class TikTokUserService
{
    private const API_URL = 'https://www.tikwm.com/api/user/story';

    /**
     * @return array{videos: array, cursor: int, hasMore: bool}|null
     */
    public function fetchPosts(string $uniqueId, int $count = 12, int $cursor = 0): ?array
    {
        $query = http_build_query([
            'unique_id' => '@' . ltrim($uniqueId, '@'),
            'count'     => $count,
            'cursor'    => $cursor,
            'web'       => 1,
            'hd'        => 1,
        ]);

        $ch = curl_init(self::API_URL . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'TikTok user/posts cURL error: ' . curl_error($ch), ['unique_id' => $uniqueId]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!isset($decoded['code']) || $decoded['code'] !== 0 || !isset($decoded['data']['videos'])) {
            Logger::write('warning', 'TikTok user/posts API returned an error', ['unique_id' => $uniqueId, 'response' => $decoded]);
            return null;
        }

        $videos = $decoded['data']['videos'];
        foreach ($videos as $video) {
            $this->cacheVideo($video);
        }

        return [
            'videos'  => $videos,
            'cursor'  => (int) ($decoded['data']['cursor'] ?? ($cursor + count($videos))),
            // hasMore isn't confirmed present on every response — falling
            // back to "got a full page, there's probably more" if absent.
            'hasMore' => (bool) ($decoded['data']['hasMore'] ?? (count($videos) >= $count)),
        ];
    }

    /** video_id is a TikTok snowflake ID — globally unique, so no need to key on the username too. */
    private function cacheVideo(array $video): void
    {
        $id = (string) ($video['video_id'] ?? $video['id'] ?? '');
        if ($id === '') {
            return;
        }
        try {
            $pdo = Database::getInstance();
            $ttl = (int) Config::get('cache_ttl', 3600);
            $stmt = $pdo->prepare(
                'INSERT INTO cache (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                 ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)'
            );
            $stmt->execute(['k' => 'tkuser_video_' . $id, 'v' => json_encode($video), 'ttl' => $ttl]);
        } catch (Throwable $e) {
            // Cache is best-effort — a failure here shouldn't break the listing.
            Logger::write('warning', 'TikTok user video cache write failed', [
                'video_id' => $id,
                'db_error' => $e->getMessage(),
            ]);
        }
    }

    public function getCachedVideo(string $videoId): ?array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('SELECT cache_value FROM cache WHERE cache_key = :k AND expires_at > NOW()');
            $stmt->execute(['k' => 'tkuser_video_' . $videoId]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['cache_value'], true) : null;
        } catch (Throwable $e) {
            Logger::write('warning', 'TikTok user video cache read failed', [
                'video_id' => $videoId,
                'db_error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
