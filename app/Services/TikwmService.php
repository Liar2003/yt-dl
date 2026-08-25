<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Logger;
use Throwable;

/**
 * TikTok extraction via the TikWM API (https://tikwm.com/api/). Results
 * are cached in the `cache` table for cache_ttl seconds to avoid
 * re-hitting TikWM for the same link.
 */
class TikwmService
{
    private const API_URL = 'https://www.tikwm.com/api/';

    /** Returns TikWM's `data` object, or null on failure. */
    public function fetch(string $url): ?array
    {
        $cacheKey = 'tikwm_' . md5($url);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['url' => $url, 'hd' => 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'TikWM cURL error: ' . curl_error($ch), ['url' => $url]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!isset($data['code']) || $data['code'] !== 0 || !isset($data['data'])) {
            Logger::write('warning', 'TikWM API returned an error', ['url' => $url, 'response' => $data]);
            return null;
        }

        $result = $data['data'];
        $this->setCache($cacheKey, $result);
        return $result;
    }

    public function detectType(array $data): string
    {
        return !empty($data['images']) ? 'image' : 'video';
    }

    public function getVideoUrl(array $data): ?string
    {
        // fetch() asks for hd=1, so hdplay carries the no-watermark HD
        // rendition when one exists; play is the standard-quality copy.
        foreach (['hdplay', 'play'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }
        return null;
    }

    public function getAudioUrl(array $data): ?string
    {
        return $data['music'] ?? null;
    }

    /** @return string[] */
    public function getImages(array $data): array
    {
        return $data['images'] ?? [];
    }

    private function getCache(string $key): ?array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('SELECT cache_value FROM cache WHERE cache_key = :k AND expires_at > NOW()');
            $stmt->execute(['k' => $key]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['cache_value'], true) : null;
        } catch (Throwable $e) {
            Logger::write('warning', 'Tikwm cache read failed — refetching from API', [
                'key' => $key,
                'db_error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function setCache(string $key, array $value): void
    {
        try {
            $pdo = Database::getInstance();
            $ttl = (int) Config::get('cache_ttl', 3600);
            $stmt = $pdo->prepare(
                'INSERT INTO cache (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                 ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)'
            );
            $stmt->execute(['k' => $key, 'v' => json_encode($value), 'ttl' => $ttl]);
        } catch (Throwable $e) {
            // Cache is best-effort; a failure here shouldn't break a download.
            Logger::write('warning', 'Tikwm cache write failed — audio buttons may expire early', [
                'key' => $key,
                'db_error' => $e->getMessage(),
            ]);
        }
    }

    /** Caches a TikTok's extracted audio under a short, stable key for the "Download Audio" button. */
    public function cacheAudioUrl(string $tiktokId, ?string $audioUrl, ?string $originUrl = null): void
    {
        if ($audioUrl) {
            $this->setCache('audio_' . $tiktokId, array_filter([
                'url'    => $audioUrl,
                'origin' => $originUrl,
            ]));
        }
    }

    public function getCachedAudio(string $tiktokId): ?array
    {
        return $this->getCache('audio_' . $tiktokId);
    }

    public function getCachedAudioUrl(string $tiktokId): ?string
    {
        return $this->getCachedAudio($tiktokId)['url'] ?? null;
    }
}
