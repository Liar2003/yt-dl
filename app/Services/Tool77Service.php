<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Logger;
use Throwable;

/**
 * Client for tool77.com's "download/all" endpoint — used for Facebook
 * and YouTube only (BotController routes just those two here; TikTok
 * extraction deliberately goes through TikwmService instead).
 * Unofficial, undocumented third-party API. Three things worth
 * knowing before relying on this in production:
 *
 * 1. The `url` field in every format entry is NOT a direct link — it's
 *    base64_decode(strrev($token)). Confirmed during development by
 *    decoding real example responses: they resolve to genuine
 *    fbcdn.net / redirector.googlevideo.com URLs.
 *    resolveUrl() does this decode and validates the result actually
 *    looks like a URL before returning it, so a scheme change on
 *    tool77's end fails loud (null) instead of handing Telegram
 *    garbage.
 *
 * 2. The decoded URLs are short-lived signed CDN links — YouTube's
 *    googlevideo.com ones carry their own `expire=` timestamp
 *    (typically hours out). This service's cache TTL
 *    (tool77_cache_ttl, default 900s) is deliberately shorter than a
 *    typical "downloader" cache would be for that reason. Facebook's
 *    "Download Audio" button resolves its link only when tapped, so a
 *    tap long after the original message may hit an expired link even
 *    though it's still "cached" here; that shows up as Telegram
 *    failing to fetch the file, not as an error from this service.
 *    YouTube no longer delivers media through the bot either — its
 *    menu buttons point at short index.php?dl=1… links and the real
 *    CDN URL is re-resolved from this cache only when tapped, so a
 *    button's lifetime is exactly this TTL (see BotController's
 *    buildYoutubeKeyboard()).
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

    /** The only video heights YouTube menus offer — anything else (2160p, 144p, …) is dropped. */
    public const MENU_HEIGHTS = [1080, 720, 480, 360];

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
     * YouTube menu: download candidates for the fixed height ladder
     * (MENU_HEIGHTS, highest first). data['normals'] entries carry
     * their own audio track; data['videos'] ones are video-only —
     * YouTube only serves combined streams at 360p and below, so
     * higher rungs always come out of `videos` and play back silent.
     * At each height a combined format beats a video-only one, and
     * among video-only duplicates the most broadly playable codec
     * wins (avc1 mp4 over vp9/av01 webm). Entries whose url token
     * fails to resolve are skipped. Returns [] when nothing usable.
     *
     * @return array<int, array{url: string, hasAudio: bool}> keyed by height, MENU_HEIGHTS order
     */
    public function getVideoQualities(array $data, ?array $heights = null): array
    {
        $heights = $heights ?? self::MENU_HEIGHTS;

        $combined = [];
        foreach ($this->withHeight($data['normals'] ?? []) as $entry) {
            $combined[$entry['height']] ??= $entry;
        }

        $videoOnly = [];
        foreach ($this->withHeight($data['videos'] ?? []) as $entry) {
            $h = (int) $entry['height'];
            if (!isset($videoOnly[$h]) || $this->codecRank($entry) < $this->codecRank($videoOnly[$h])) {
                $videoOnly[$h] = $entry;
            }
        }

        $menu = [];
        foreach ($heights as $h) {
            if (isset($combined[$h]) && ($url = $this->resolveUrl($combined[$h]))) {
                $menu[$h] = ['url' => $url, 'hasAudio' => true];
            } elseif (isset($videoOnly[$h]) && ($url = $this->resolveUrl($videoOnly[$h]))) {
                $menu[$h] = ['url' => $url, 'hasAudio' => false];
            }
        }
        return $menu;
    }

    /**
     * YouTube menu: best track (highest kb/s in its label) per audio
     * format — m4a and opus for typical videos. Ordered by how
     * universally playable each format is (m4a first).
     *
     * @return array<string, array{url: string, kbps: int}> keyed by extension
     */
    public function getAudioFormats(array $data): array
    {
        $best = [];
        foreach ($data['audios'] ?? [] as $entry) {
            if (empty($entry['url'])) {
                continue;
            }
            $ext = strtolower((string) ($entry['extension'] ?? ''));
            if ($ext === '') {
                continue;
            }
            preg_match('/(\d+)\s*kb\/s/i', (string) ($entry['label'] ?? ''), $m);
            $kbps = (int) ($m[1] ?? 0);
            if (!isset($best[$ext]) || $kbps > $best[$ext]['kbps']) {
                $best[$ext] = ['kbps' => $kbps, 'entry' => $entry];
            }
        }

        $out = [];
        foreach ($best as $ext => $info) {
            if ($url = $this->resolveUrl($info['entry'])) {
                $out[$ext] = ['url' => $url, 'kbps' => $info['kbps']];
            }
        }
        uksort($out, fn($a, $b) => $this->audioFormatRank($a) <=> $this->audioFormatRank($b));
        return $out;
    }

    /** Drops entries without a positive height so callers can index on it safely. */
    private function withHeight(array $entries): \Generator
    {
        foreach ($entries as $entry) {
            if (!empty($entry['url']) && (int) ($entry['height'] ?? 0) > 0) {
                $entry['height'] = (int) $entry['height'];
                yield $entry;
            }
        }
    }

    /** Lower rank = more compatible player support. avc1-in-mp4 plays nearly everywhere. */
    private function codecRank(array $entry): int
    {
        $mime = strtolower((string) ($entry['mimeType'] ?? ''));
        if (str_contains($mime, 'avc1')) {
            return 0;
        }
        if (str_contains($mime, 'mp4')) {
            return 1;
        }
        return 2;
    }

    private function audioFormatRank(string $ext): int
    {
        return match ($ext) {
            'm4a', 'mp3' => 0,
            'aac'        => 1,
            default      => 2, // opus & friends: great quality, spotty native support
        };
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
            Logger::write('warning', 'Tool77 cache read failed — refetching from API', [
                'key' => 'tool77_' . $key,
                'db_error' => $e->getMessage(),
            ]);
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
            Logger::write('warning', 'Tool77 cache write failed — links will expire sooner', [
                'key' => 'tool77_' . $key,
                'db_error' => $e->getMessage(),
            ]);
        }
    }
}
