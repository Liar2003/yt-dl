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

    /**
     * Rewrites a Facebook URL into the exact shape tool77's API accepts —
     * "https://www.facebook.com/<type>/<id>". Anything extra, e.g. reel
     * links carrying tracking junk like
     * /reel/1659014385378197/?mibextid=9…&s=y…&fs=e, come back as a
     * "This platform…" fail from the API. So: fold m./web. hosts onto
     * www., drop the query string / fragment and any trailing slash
     * everywhere except /watch/ pages, whose video ID lives in ?v=.
     */
    public static function normalizeFacebookUrl(string $url): string
    {
        if (!self::isFacebookUrl($url)) {
            return $url;
        }

        // Canonical host: tool77 wants www.facebook.com.
        $url = preg_replace_callback(
            '#^(https?://)(?:www\.|web\.|m\.)?(facebook\.com)(/|$)#i',
            fn($m) => $m[1] . 'www.' . $m[2] . $m[3],
            $url
        );

        // /watch/ pages keep only the video-ID param — the ID lives in the query there.
        if (preg_match('#^(https?://www\.facebook\.com/watch/?)#i', $url, $m)
            && preg_match('#[?&]v=(\d+)#i', $url, $v)
        ) {
            return $m[1] . '?v=' . $v[1];
        }

        // Everything else: cut query/fragment, then trailing slash.
        return rtrim(preg_replace('~[?#].*$~', '', $url), '/');
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
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($effective) || !preg_match('#^https?://#i', $effective)) {
            Logger::write('warning', 'Short-link redirect unresolved — using the input URL as-is', [
                'url'        => $url,
                'curl_error' => $error !== '' ? $error : null,
                'effective'  => is_string($effective) ? $effective : null,
            ]);
        }

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
            $error = curl_error($ch);
            curl_close($ch);
            Logger::write('warning', 'Remote file size check failed — falling back to direct URL delivery', [
                'url'        => $url,
                'curl_error' => $error,
            ]);
            return null;
        }
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        if ($size <= 0) {
            Logger::write('info', 'Remote file size unavailable (no Content-Length) — sending by URL directly', [
                'url' => $url,
            ]);
            return null;
        }
        return (int) $size;
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
