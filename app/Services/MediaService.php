<?php

namespace App\Services;

use App\Helpers\Logger;

/**
 * Downloads remote media into storage/temp/ for the large-video
 * (>20MB) upload path, and cleans up afterwards.
 */
class MediaService
{
    private string $tempDir;

    public function __construct()
    {
        $this->tempDir = __DIR__ . '/../../storage/temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0775, true);
        }
    }

    /**
     * Downloads a remote file into storage/temp/ and returns its path.
     * $referer is needed for googlevideo.com links — Google's CDN
     * rejects fetches that don't carry a youtube.com Referer.
     */
    public function downloadToTemp(string $url, string $extension = 'mp4', ?string $referer = null): ?string
    {
        $path = $this->tempDir . '/' . uniqid('media_', true) . '.' . $extension;

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            Logger::write('error', 'Could not open temp file for writing', ['path' => $path]);
            return null;
        }

        $options = [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ];
        if ($referer) {
            $options[CURLOPT_HTTPHEADER] = ['Referer: ' . $referer];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok) {
            Logger::write('error', 'MediaService download failed: ' . $error, ['url' => $url]);
            @unlink($path);
            return null;
        }

        return $path;
    }

    public function cleanup(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Sweeps storage/temp/ of anything older than $maxAgeSeconds — wire this into a cron job. */
    public function cleanupOldTempFiles(int $maxAgeSeconds = 3600): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file) && (time() - filemtime($file)) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }
}
