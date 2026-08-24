<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Logger;
use Throwable;

/**
 * Client for tool77.com's "download/all" endpoint — used for TikTok,
 * Facebook, and YouTube alike (BotController routes all three of them
 * here). Unofficial, undocumented third-party API. Three things worth
 * knowing before relying on this in production:
 *
 * 1. The `url` field in every format entry is NOT a direct link — it's
 *    base64_decode(strrev($token)). Confirmed during development by
 *    decoding real example responses: they resolve to genuine
 *    tiktokcdn.com / fbcdn.net / redirector.googlevideo.com URLs.
 *    resolveUrl() does this decode and validates the result actually
 *    looks like a URL before returning it, so a scheme change on
 *    tool77's end fails loud (null) instead of handing Telegram
 *    garbage.
 *
 * 2. The decoded URLs are short-lived signed CDN links — YouTube's
 *    googlevideo.com ones carry their own `expire=` timestamp. This
 *    service's cache TTL (tool77_cache_ttl, default 900s) is
 *    deliberately shorter than a typical "downloader" cache would be
 *    for that reason — a "Download Video"/"Download Audio" button
 *    tapped long after the original message may hit an expired link
 *    even though it's still "cached" here; that shows up as Telegram
 *    failing to fetch the file, not as an error from this service.
 *
 * 3. tool77.com's own web UI gates downloads behind a bot-check
 *    ("wait a few seconds" / browser extension) — but that lives in
 *    their frontend. This service only calls the POST
 *    /download/all/request JSON endpoint directly, the same way the
 *    example responses used during development were captured, and
 *    hasn't hit that gate doing so. If tool77 ever extends that check
 *    to the raw API, fetch() starts getting non-"success" responses
 *    rather than failing silently — worth an occasional glance at
 *    storage/logs/app.log for "Tool77 API returned an error".
 */
class Tool77Service
{
    private const API_URL = 'https://www.tool77.com/en/v/download/all/request';

    public function fetch(string $url): ?array
    {
        $id = $this->cacheId($url);
        $cached = $this->getCache($id);
        if ($cached !== null) {
            return $cached;
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['url' => $url]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'Tool77 cURL error: ' . curl_error($ch), ['url' => $url]);
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!isset($decoded['code']) || $decoded['code'] !== 'success' || !isset($decoded['data'])) {
            Logger::write('warning', 'Tool77 API returned an error', ['url' => $url, 'response' => $decoded]);
            return null;
        }

        $data = $decoded['data'];
        $this->setCache($id, $data);
        return $data;
    }

    /** Deterministic short key for a URL — used both as the cache key and to reference a fetched result from callback_data (Telegram's 64-byte limit rules out embedding the full URL there). */
    public function cacheId(string $url): string
    {
        return md5($url);
    }

    public function getCachedById(string $id): ?array
    {
        return $this->getCache($id);
    }

    /** Best combined audio+video format from data['normals'] — the only entries guaranteed playable with sound as-is. */
    public function getBestNormal(array $data): ?array
    {
        return $this->pickBest($data['normals'] ?? [], true);
    }

    /** Best audio-only track from data['audios']. */
    public function getBestAudio(array $data): ?array
    {
        return $this->pickBest($data['audios'] ?? [], false);
    }

    /**
     * Resolved image URLs for a TikTok photo-carousel post
     * (data['images']). No confirmed example of this array's shape was
     * available during development — every other array in this API
     * uses {name, url, ...} objects with an obfuscated url token, so
     * entries here are decoded the same way if they're objects; a
     * plain string entry is passed through as-is in case images turn
     * out not to need the same obfuscation. Returns whichever entries
     * successfully resolve to something URL-shaped, silently dropping
     * the rest — worth spot-checking against a real carousel post.
     */
    public function getImageUrls(array $data): array
    {
        $urls = [];
        foreach ($data['images'] ?? [] as $entry) {
            if (is_string($entry) && preg_match('#^https?://#i', $entry)) {
                $urls[] = $entry;
            } elseif (is_array($entry)) {
                $resolved = $this->resolveUrl($entry);
                if ($resolved) {
                    $urls[] = $resolved;
                }
            }
        }
        return $urls;
    }

    /**
     * Resolves a format entry's `url` token into a real, fetchable
     * link. Returns null (rather than a garbage string) if it doesn't
     * decode to something that looks like a URL — see point 1 above.
     */
    public function resolveUrl(array $entry): ?string
    {
        $token = $entry['url'] ?? null;
        if (!$token || !is_string($token)) {
            return null;
        }
        $decoded = base64_decode(strrev($token), true);
        if ($decoded === false || !preg_match('#^https?://#i', $decoded)) {
            return null;
        }
        return $decoded;
    }

    private function pickBest(array $entries, bool $isVideo): ?array
    {
        $entries = array_values(array_filter($entries, fn($e) => !empty($e['url'])));
        if (!$entries) {
            return null;
        }
        usort($entries, fn($a, $b) => $this->score($b, $isVideo) <=> $this->score($a, $isVideo));
        return $entries[0];
    }

    private function score(array $entry, bool $isVideo): int
    {
        $quality = strtolower((string) ($entry['quality'] ?? ''));

        if ($isVideo) {
            $score = 0;
            if ($quality === 'watermark') {
                $score -= 10000; // plain "watermark" — avoid unless it's the only option
            }
            if (str_contains($quality, 'hd')) {
                $score += 500;
            }
            $score += (int) round((($entry['width'] ?? 0) * ($entry['height'] ?? 0)) / 1000);
            return $score;
        }

        if (preg_match('/(\d+)\s*kb\/s/i', (string) ($entry['label'] ?? ''), $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    private function getCache(string $key): ?array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('SELECT cache_value FROM cache WHERE cache_key = :k AND expires_at > NOW()');
            $stmt->execute(['k' => 'tool77_' . $key]);
            $row = $stmt->fetch();
            return $row ? json_decode($row['cache_value'], true) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function setCache(string $key, array $value): void
    {
        try {
            $pdo = Database::getInstance();
            $ttl = (int) Config::get('tool77_cache_ttl', 900);
            $stmt = $pdo->prepare(
                'INSERT INTO cache (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, DATE_ADD(NOW(), INTERVAL :ttl SECOND))
                 ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expires_at = VALUES(expires_at)'
            );
            $stmt->execute(['k' => 'tool77_' . $key, 'v' => json_encode($value), 'ttl' => $ttl]);
        } catch (Throwable $e) {
            // Cache is best-effort; a failure here shouldn't break a download.
        }
    }
}
