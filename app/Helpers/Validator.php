<?php

namespace App\Helpers;

/**
 * Stateless input validation / extraction helpers.
 */
class Validator
{
    public static function isTikTokUrl(string $text): bool
    {
        return (bool) preg_match('#https?://(www\.|vm\.|vt\.|m\.)?tiktok\.com/\S+#i', $text);
    }

    public static function isFacebookUrl(string $text): bool
    {
        return (bool) preg_match(
            '#https?://((www\.|web\.|m\.)?facebook\.com/|(www\.)?fb\.watch/|fb\.com/)\S+#i',
            $text
        );
    }

    public static function isYouTubeUrl(string $text): bool
    {
        return (bool) preg_match(
            '#https?://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/)|youtu\.be/)\S+#i',
            $text
        );
    }

    /**
     * True for the short/redirecting variants of the supported
     * platforms — vm./vt.tiktok.com, youtu.be, fb.watch — whose real
     * URL only appears in the redirect chain, so they must be resolved
     * (resolveRedirect) before ID extraction / caching works.
     */
    public static function isShortLink(string $url): bool
    {
        return (bool) preg_match(
            '#https?://((vm|vt)\.tiktok\.com/|youtu\.be/|(www\.)?fb\.watch/)#i',
            $url
        );
    }

    /**
     * Follows a short link's redirect chain and returns the final
     * destination URL. Falls back to the input unchanged if the
     * request fails or no final URL could be determined — callers
     * treat the result as "best-known canonical URL".
     */
    public static function resolveRedirect(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        curl_exec($ch);
        $effective = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return is_string($effective) && preg_match('#^https?://#i', $effective) ? $effective : $url;
    }

    /**
     * Pulls the 11-character video ID out of any standard YouTube URL
     * shape (watch?v=, youtu.be/, shorts/, embed/). Returns null for
     * anything else.
     */
    public static function extractYouTubeId(string $url): ?string
    {
        if (preg_match(
            '#(?:youtube\.com/(?:watch\?(?:[^\s]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#i',
            $url,
            $m
        )) {
            return $m[1];
        }
        return null;
    }

    /**
     * Pulls the first http(s) URL out of an arbitrary message, trimming
     * common trailing punctuation a user might paste along with it.
     */
    public static function extractUrl(string $text): ?string
    {
        if (preg_match('#https?://\S+#i', $text, $m)) {
            return rtrim($m[0], '.,)');
        }
        return null;
    }

    /**
     * HEAD request to check a remote file's size without downloading it.
     * Returns null if the size can't be determined.
     */
    public static function getRemoteFileSize(string $url): ?int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            curl_close($ch);
            return null;
        }
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        return $size > 0 ? (int) $size : null;
    }

    /**
     * Escapes text for Telegram's legacy `parse_mode=Markdown` (what
     * TelegramService uses everywhere). Legacy Markdown only supports
     * backslash-escaping for these four characters — unlike
     * MarkdownV2, punctuation like . ! ( ) does not need escaping and
     * escaping it would show a literal backslash.
     */
    public static function markdownEscape(string $text): string
    {
        $chars = ['_', '*', '`', '['];
        return str_replace($chars, array_map(fn($c) => '\\' . $c, $chars), $text);
    }
}
