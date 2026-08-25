<?php

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Helpers\Logger;
use App\Helpers\Validator;
use App\Models\Ad;
use App\Models\Download;
use App\Models\PendingBroadcast;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdsService;
use App\Services\BroadcastService;
use App\Services\ForceJoinService;
use App\Services\MediaService;
use App\Services\StatisticsService;
use App\Services\TelegramService;
use App\Services\TikTokUserService;
use App\Services\TikwmService;
use App\Services\Tool77Service;
use App\Services\YoutubeSearchService;
use Throwable;

/**
 * Routes every incoming Telegram update to the right handler. This is
 * the class Router::handleWebhook() calls into.
 *
 * Platform routing: Facebook and YouTube extract via Tool77Service —
 * one client for tool77.com's "download/all" endpoint (see that
 * class's docblock for how the response's obfuscated url tokens get
 * resolved into real, fetchable links, and the caveats that come with
 * an unofficial API). TikTok deliberately does NOT touch tool77:
 * links and /username post picks alike go through TikwmService, the
 * same client the web downloader uses. TikTokUserService is separate
 * again — it only handles browsing a TikTok user's video list;
 * picking a video from that list hands off to handleTikTokUrl() like
 * any other TikTok link.
 */
class BotController
{
    private TelegramService $telegram;
    private Tool77Service $tool77;
    private TikwmService $tikwm;
    private TikTokUserService $tiktokUser;
    private YoutubeSearchService $ytSearch;
    private MediaService $media;
    private ForceJoinService $forceJoin;
    private StatisticsService $stats;
    private AdsService $ads;

    public function __construct()
    {
        $this->telegram   = new TelegramService();
        $this->tool77     = new Tool77Service();
        $this->tikwm      = new TikwmService();
        $this->tiktokUser = new TikTokUserService();
        $this->ytSearch  = new YoutubeSearchService();
        $this->media     = new MediaService();
        $this->forceJoin = new ForceJoinService();
        $this->stats     = new StatisticsService();
        $this->ads       = new AdsService();
    }

    public function processUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        if (!isset($update['message'])) {
            return;
        }

        $message = $update['message'];
        $from = $message['from'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if (!$from || !$chatId) {
            return;
        }

        $telegramId = (int) $from['id'];

        // Everything from here down to the command router touches the
        // database — on a brand-new deployment those tables don't exist
        // yet. Instead of dying before a single command gets handled,
        // note the failure and keep going: slash commands still route
        // (that's what makes the admin's /setup able to create the
        // missing tables), while link traffic gets told the bot isn't
        // ready.
        $dbReady = true;
        try {
            if (Setting::isTrue('maintenance_mode', false) && !User::isAdmin($telegramId)) {
                $this->telegram->sendMessage($chatId, "🛠 The bot is under maintenance. Please try again shortly.");
                return;
            }

            $isNewUser = User::findByTelegramId($telegramId) === null;
            User::registerOrUpdate($from);
            if ($isNewUser) {
                $this->stats->recordNewUser();
                $this->notifyAdminsNewUser($from, $message);
            }

            if (User::isBanned($telegramId)) {
                $this->telegram->sendMessage($chatId, "🚫 You've been banned from using this bot.");
                return;
            }

            // Any message an admin forwards to the bot is either the
            // broadcast they just armed with /forward, or — if they
            // didn't — gets auto-captured as an ad. Checked before the
            // empty-text return below since this content (a photo, a
            // video) often has no `text` field at all, only a caption or
            // none.
            if (User::isAdmin($telegramId) && $this->isForwardedMessage($message)) {
                if (PendingBroadcast::isPending($telegramId)) {
                    $this->handleBroadcastForward((int) $chatId, $telegramId, $message);
                } else {
                    $this->handleAdForward((int) $chatId, $telegramId, $message);
                }
                return;
            }
        } catch (Throwable $e) {
            $dbReady = false;
            Logger::write('warning', 'DB preamble failed — routing as unready: ' . $e->getMessage(), [
                'telegram_id' => $telegramId,
            ]);
        }

        if ($text === '') {
            return;
        }

        if ($text[0] === '/') {
            $this->handleCommand($text, (int) $chatId, $from);
            return;
        }

        if (!$dbReady) {
            $this->telegram->sendMessage($chatId, "⚠️ The bot isn't set up yet — please check back soon.");
            return;
        }

        if (Validator::isTikTokUrl($text)) {
            $this->handleTikTokUrl((int) $chatId, $telegramId, Validator::extractUrl($text) ?? $text);
            return;
        }

        if (Validator::isFacebookUrl($text)) {
            $this->handleFacebookUrl((int) $chatId, $telegramId, Validator::extractUrl($text) ?? $text);
            return;
        }

        if (Validator::isYouTubeUrl($text)) {
            $this->handleYouTubeUrl((int) $chatId, $telegramId, Validator::extractUrl($text) ?? $text);
            return;
        }

        $this->handleTextSearch((int) $chatId, $telegramId, $text);
    }

    private function handleCommand(string $text, int $chatId, array $from): void
    {
        $command = strtolower(explode('@', explode(' ', $text)[0])[0]);

        switch ($command) {
            case '/start':
                $this->telegram->sendMessage(
                    $chatId,
                    "👋 *Welcome!*\n\n" .
                    "I download videos from TikTok, Facebook & YouTube — free, no watermark.\n\n" .
                    "*Just send me:*\n" .
                    "• TikTok link → video/photos + 🎵 audio\n" .
                    "• Facebook link → video + 🎵 audio\n" .
                    "• YouTube link → pick 1080p–360p or 🎵 audio\n" .
                    "• Any word → YouTube search results\n" .
                    "• /username @handle → browse their videos\n\n" .
                    "Type /help for everything I can do." . $this->personalStatsLine((int) $from['id'])
                );
                return;

            case '/help':
                $text =
                    "*How to use this bot*\n\n" .
                    "*Downloads* — just paste a link:\n" .
                    "• TikTok → video (no watermark), photo albums, 🎵 audio button\n" .
                    "• Facebook → best video + 🎵 audio button\n" .
                    "• YouTube → buttons: 1080p/720p/480p/360p 🔇 + 🎵 m4a/opus\n" .
                    "• Any text → YouTube search, tap a result\n\n" .
                    "*TikTok profiles*\n" .
                    "• /username @handle → recent videos, tap to download\n\n" .
                    "*Commands*\n" .
                    "/start · /help · /about · /username";

                if ($this->isAdminSafe((int) $from['id'])) {
                    $text .=
                        "\n\n🛠 *Admin* — /admin for the dashboard\n" .
                        "/users /ban /unban /history /list /top\n" .
                        "/forcejoin /addchannel /removechannel /channels\n" .
                        "/broadcast /forward /stats /logs /errors\n" .
                        "/addadmin /removeadmin /admins\n" .
                        "/ads /adslist /adsremove · forward = new ad\n" .
                        "/maintenance /setup";
                } else {
                    $text .= "\n\n💡 Download buttons last about an hour — resend the link if one expires.";
                }

                $this->telegram->sendMessage($chatId, $text);
                return;

            case '/about':
                $this->telegram->sendMessage($chatId, "🤖 *TikTok, Facebook & YouTube Downloader Bot*\nBuilt in pure PHP, no framework.");
                return;

            case '/story':
                $arg = trim(substr($text, strlen(explode(' ', $text, 2)[0])));
                if ($arg === '') {
                    $this->telegram->sendMessage($chatId, "Usage: /username @tiktokhandle");
                    return;
                }
                $this->handleUsernameLookup($chatId, (int) $from['id'], $arg);
                return;

            default:
                // User::isAdmin() answers from config alone for the
                // bootstrap admin, so /setup reaches AdminController
                // even with no tables yet. Any other admin check hits
                // the DB and lands in the catch below.
                try {
                    if (User::isAdmin((int) $from['id'])) {
                        (new AdminController())->handleCommand($text, $chatId, (int) $from['id']);
                        return;
                    }
                    $this->telegram->sendMessage($chatId, "Unknown command. Try /help.");
                } catch (Throwable $e) {
                    Logger::write('error', 'Admin dispatch failed: ' . $e->getMessage(), [
                        'command' => $command,
                        'telegram_id' => (int) $from['id'],
                    ]);
                    $this->telegram->sendMessage(
                        $chatId,
                        "⚠️ Database not ready. If you're the bot owner, send /setup to create the tables."
                    );
                }
        }
    }

    /**
     * Admin check that can't throw: User::isAdmin() answers from config
     * alone for the bootstrap admin but hits the DB for everyone else,
     * so on a brand-new deployment it fails — treat that as non-admin
     * rather than breaking /help.
     */
    private function isAdminSafe(int $telegramId): bool
    {
        try {
            return User::isAdmin($telegramId);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Personal touch for returning users: their lifetime download
     * count appended to /start. Cosmetic only — a DB that isn't set up
     * yet (commands route before /setup) or briefly unreachable just
     * yields an empty string rather than failing the greeting.
     */
    private function personalStatsLine(int $telegramId): string
    {
        try {
            $count = Download::countForUser($telegramId);
        } catch (Throwable) {
            return '';
        }
        return $count > 0
            ? "\n\n📊 You've downloaded *" . number_format($count) . '* ' . ($count === 1 ? 'file' : 'files') . ' with me so far.'
            : '';
    }

    /**
     * Every brand-new user triggers a full-detail report to all admins
     * (the bootstrap admin_telegram_id from config plus everyone in the
     * admins table). Best-effort by design: this runs inside the DB
     * preamble where a half-set-up database is exactly the interesting
     * case, and a failing admin ping must never break the new user's
     * own request — so everything is wrapped.
     */
    private function notifyAdminsNewUser(array $from, array $message): void
    {
        try {
            $ids = [(int) Config::get('admin_telegram_id')];
            foreach (User::listAdmins() as $admin) {
                $ids[] = (int) $admin['telegram_id'];
            }

            $text = $this->buildNewUserReport($from, $message);
            foreach (array_unique($ids) as $id) {
                // A new user who happens to be an admin shouldn't get a report about themselves.
                if ($id > 0 && $id !== (int) $from['id']) {
                    $this->telegram->sendMessage($id, $text);
                }
            }
        } catch (Throwable $e) {
            Logger::write('warning', 'New-user admin report failed: ' . $e->getMessage(), [
                'telegram_id' => (int) ($from['id'] ?? 0),
            ]);
        }
    }

    private function buildNewUserReport(array $from, array $message): string
    {
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $username = (string) ($from['username'] ?? '');

        $lines = [
            "🆕 *New User Alert*",
            '',
            "👤 Name: " . Validator::markdownEscape($name !== '' ? $name : '-'),
            "🔗 Username: " . ($username !== '' ? '@' . Validator::markdownEscape($username) : '-'),
            "🆔 ID: `{$from['id']}`",
            "🔗 [Open profile](tg://user?id={$from['id']})",
        ];

        if (!empty($from['language_code'])) {
            $lines[] = "🌐 Language: {$from['language_code']}";
        }
        $lines[] = "💎 Premium: " . (!empty($from['is_premium']) ? 'Yes' : 'No');

        $chatType = (string) ($message['chat']['type'] ?? '');
        $source = match ($chatType) {
            'private'    => 'Private chat',
            'supergroup' => 'Group',
            'group'      => 'Basic group',
            'channel'    => 'Channel',
            default      => $chatType !== '' ? ucfirst($chatType) : 'Unknown',
        };
        if (in_array($chatType, ['group', 'supergroup'], true) && !empty($message['chat']['title'])) {
            $source .= ': ' . Validator::markdownEscape((string) $message['chat']['title']);
        }
        $lines[] = "💬 Via: {$source}";

        try {
            $total = (int) Database::getInstance()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $lines[] = '';
            $lines[] = "👥 Total registered users: " . number_format($total);
        } catch (Throwable) {
            // Count is garnish; skip it rather than fail the report.
        }

        $lines[] = "🕒 Joined: " . gmdate('Y-m-d H:i:s') . ' UTC';
        return implode("\n", $lines);
    }

    /**
     * Telegram Bot API 7.0+ uses a unified `forward_origin` object;
     * older clients/API versions may still send the individual
     * forward_date/forward_from/forward_from_chat/forward_sender_name
     * fields instead — checking for any of them covers both.
     */
    private function isForwardedMessage(array $message): bool
    {
        return isset($message['forward_origin'])
            || isset($message['forward_date'])
            || isset($message['forward_from'])
            || isset($message['forward_from_chat'])
            || isset($message['forward_sender_name']);
    }

    /**
     * Stores a reference to the forwarded message (which now lives in
     * this admin's chat with the bot) rather than its content — see
     * Ad model's docblock. Any message type works: text, photo, video,
     * document, whatever the admin forwards.
     */
    private function handleAdForward(int $chatId, int $adminId, array $message): void
    {
        $messageId = (int) ($message['message_id'] ?? 0);
        if (!$messageId) {
            return;
        }

        $id = Ad::create($chatId, $messageId, $adminId);
        $status = $this->ads->isEnabled() ? '' : " Ads are currently off — turn them on with /ads on.";
        $this->telegram->sendMessage($chatId, "✅ Ad #{$id} saved.{$status}");
    }

    /** Consumes the /forward arming set by AdminController::startForwardBroadcast() and sends the forwarded message to every user. */
    private function handleBroadcastForward(int $chatId, int $adminId, array $message): void
    {
        PendingBroadcast::clear($adminId);

        $messageId = (int) ($message['message_id'] ?? 0);
        if (!$messageId) {
            return;
        }

        $this->telegram->sendMessage($chatId, "📣 Broadcasting to all active users…");
        $result = (new BroadcastService())->sendForwarded($chatId, $messageId);
        $this->telegram->sendMessage(
            $chatId,
            "✅ Sent to {$result['success']}/{$result['total']} users ({$result['failed']} failed)."
        );
    }

    /**
     * TikTok: video primary (or a photo carousel), with a 🎵 Download
     * Audio button. Extraction runs through TikwmService — tool77 is
     * reserved for Facebook and YouTube only.
     */
    public function handleTikTokUrl(int $chatId, int $userId, string $url): void
    {
        if (Validator::isShortLink($url)) {
            $url = Validator::resolveRedirect($url);
        }

        if (!$this->forceJoin->checkAll($userId)) {
            $this->storePendingRequest($userId, $chatId, $url);
            $this->forceJoin->sendJoinPrompt($chatId);
            return;
        }

        $this->telegram->sendChatAction($chatId, 'typing');

        $data = $this->tikwm->fetch($url);
        if (!$data) {
            $this->telegram->sendMessage($chatId, "❌ Couldn't fetch that TikTok link. It may be private, deleted, or invalid.");
            return;
        }

        $title = Validator::markdownEscape((string) ($data['title'] ?? ''));
        $tiktokId = (string) ($data['id'] ?? md5($url));
        $audioUrl = $this->tikwm->getAudioUrl($data);
        $this->tikwm->cacheAudioUrl($tiktokId, $audioUrl, $url);

        $keyboard = $audioUrl
            ? ['inline_keyboard' => [[['text' => '🎵 Download Audio', 'callback_data' => 'tkaud_' . $tiktokId]]]]
            : null;

        $images = $this->tikwm->getImages($data);
        if ($images) {
            // Slides backed by a TikTok live photo carry an MP4 in
            // live_images (index-paired with images) — send those as
            // videos so users get the animated version, not a still.
            $liveImages = $this->tikwm->getLiveImages($data);
            $this->telegram->sendChatAction($chatId, $liveImages ? 'upload_video' : 'upload_photo');
            $this->telegram->sendMediaGroup($chatId, $this->buildCarouselMedia($images, $liveImages));
            $this->telegram->sendMessage($chatId, $title !== '' ? $title : 'Here you go 👆', $keyboard);
            $this->saveDownload($userId, $url, 'image');
            $this->stats->recordDownload();
            $this->ads->maybeShow($chatId);
            return;
        }

        $videoUrl = $this->tikwm->getVideoUrl($data);
        if (!$videoUrl) {
            $this->telegram->sendMessage($chatId, "❌ No downloadable video found for that link.");
            return;
        }

        $this->deliverVideo($chatId, $videoUrl, $title, $keyboard);
        $this->saveDownload($userId, $url, 'video');
        $this->stats->recordDownload();
        $this->ads->maybeShow($chatId);
    }

    /**
     * Pairs carousel slides with their live-photo videos: slide i with
     * a live_images entry goes out as a video media item, the rest as
     * plain photo URLs (sendMediaGroup's default).
     *
     * @param string[] $images
     * @param string[] $liveImages
     * @return array<array{type:string,media:string}>|string[]
     */
    private function buildCarouselMedia(array $images, array $liveImages): array
    {
        $items = [];
        foreach ($images as $i => $url) {
            if (!empty($liveImages[$i])) {
                $items[] = ['type' => 'video', 'media' => $liveImages[$i]];
            } else {
                $items[] = $url;
            }
        }
        return $items;
    }

    /**
     * Facebook: fetch via tool77, deliver the best combined-audio+video
     * format, with a 🎵 Download Audio button when a separate audio
     * track exists.
     */
    public function handleFacebookUrl(int $chatId, int $userId, string $url): void
    {
        if (Validator::isShortLink($url)) {
            $url = Validator::resolveRedirect($url);
        }
        // tool77 only accepts the plain https://www.facebook.com/<type>/<id>
        // shape — strip tracking queries etc. before it sees the link.
        $url = Validator::normalizeFacebookUrl($url);

        if (!$this->forceJoin->checkAll($userId)) {
            $this->storePendingRequest($userId, $chatId, $url);
            $this->forceJoin->sendJoinPrompt($chatId);
            return;
        }

        $this->telegram->sendChatAction($chatId, 'typing');

        $data = $this->tool77->fetch($url);
        if (!$data) {
            $this->telegram->sendMessage($chatId, "❌ Couldn't fetch that Facebook link. It may be private or invalid.");
            return;
        }

        $title = Validator::markdownEscape((string) ($data['title'] ?? ''));
        $id = $this->tool77->cacheId($url);

        $audio = $this->tool77->getBestAudio($data);
        $keyboard = ($audio && $audio['url'])
            ? ['inline_keyboard' => [[['text' => '🎵 Download Audio', 'callback_data' => 'dlaud_' . $id]]]]
            : null;

        $video = $this->tool77->getBestNormal($data);
        $videoUrl = $video ? $this->tool77->resolveUrl($video) : null;
        if (!$videoUrl) {
            $this->telegram->sendMessage($chatId, "❌ No downloadable video found for that link.");
            return;
        }

        $this->deliverVideo($chatId, $videoUrl, $title, $keyboard);
        $this->saveDownload($userId, (string) ($data['originUrl'] ?? $url), 'facebook_video');
        $this->stats->recordDownload();
        $this->ads->maybeShow($chatId);
    }

    /** Sends a video by URL, or downloads-then-uploads for anything over 20MB (Telegram's fetch-by-URL ceiling in practice). */
    private function deliverVideo(int $chatId, string $videoUrl, string $caption, ?array $keyboard): void
    {
        $this->telegram->sendChatAction($chatId, 'upload_video');
        $size = Validator::getRemoteFileSize($videoUrl);

        if ($size !== null && $size > 20 * 1024 * 1024) {
            $localPath = $this->media->downloadToTemp($videoUrl);
            if (!$localPath) {
                $this->telegram->sendMessage($chatId, "❌ Failed to process this video. Please try again.");
                return;
            }
            $this->telegram->sendVideoLocal($chatId, $localPath, $caption, $keyboard);
            $this->media->cleanup($localPath);
        } else {
            $this->telegram->sendVideo($chatId, $videoUrl, $caption, $keyboard);
        }
    }

    /**
     * YouTube: no media file is sent to the chat. The bot replies with
     * the video's cover image as a photo message carrying the download
     * menu (fallback: plain text message if there's no thumbnail):
     * inline buttons are short download links on this server
     * (index.php re-resolves the real CDN URL when tapped, so the
     * user's browser does the actual download): video buttons for each
     * rung of Tool77Service::MENU_HEIGHTS the video actually offers
     * (1080p → 360p), plus one audio button per format tool77 returned
     * (typically m4a + opus).
     */
    public function handleYouTubeUrl(int $chatId, int $userId, string $url): void
    {
        if (Validator::isShortLink($url)) {
            $url = Validator::resolveRedirect($url);
        }

        if (!$this->forceJoin->checkAll($userId)) {
            $this->storePendingRequest($userId, $chatId, $url);
            $this->forceJoin->sendJoinPrompt($chatId);
            return;
        }

        $this->telegram->sendChatAction($chatId, 'typing');

        $videoId = Validator::extractYouTubeId($url);
        $cleanUrl = $videoId ? ('https://www.youtube.com/watch?v=' . $videoId) : $url;

        $data = $this->tool77->fetch($cleanUrl);
        if (!$data) {
            $this->telegram->sendMessage($chatId, "❌ Couldn't fetch that YouTube link. Please try again later.");
            return;
        }

        $keyboard = $this->buildYoutubeKeyboard($data, $this->downloaderBaseUrl(), $this->tool77->cacheId($cleanUrl));
        if (!$keyboard) {
            $this->telegram->sendMessage($chatId, "❌ No downloadable formats found for that video.");
            return;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $caption = ($title !== '' ? "🎬 *" . Validator::markdownEscape(mb_substr($title, 0, 256)) . "*\n" : '')
            . "Pick a quality to download:";

        // Cover + buttons in one photo message; falls back to a plain
        // text menu when the video has no usable thumbnail or Telegram
        // rejects the URL (sendPhoto retries parse errors internally,
        // anything else lands here).
        $sent = false;
        $thumbnail = (string) ($data['thumbnail'] ?? '');
        if (preg_match('#^https?://#i', $thumbnail)) {
            $this->telegram->sendChatAction($chatId, 'upload_photo');
            $result = $this->telegram->sendPhoto($chatId, $thumbnail, $caption, $keyboard);
            $sent = ($result['ok'] ?? false) === true;
            if (!$sent) {
                Logger::write('warning', 'YouTube cover send failed — falling back to text menu', [
                    'chat_id' => $chatId,
                    'thumbnail' => $thumbnail,
                    'description' => $result['description'] ?? null,
                ]);
            }
        }

        if (!$sent) {
            $this->telegram->sendMessage($chatId, $caption, $keyboard);
        }

        $this->saveDownload($userId, $cleanUrl, 'youtube_link');
        $this->stats->recordDownload();
        $this->ads->maybeShow($chatId);
    }

    /**
     * Public URL of the web downloader's redirector — index.php next
     * to webhook.php on the same host. Taken from the incoming
     * webhook's Host header, falling back to the configured webhook
     * URL's host (CLI context has no HTTP_HOST).
     */
    private function downloaderBaseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? null;
        if (!$host) {
            $host = parse_url((string) Config::get('webhook_url', ''), PHP_URL_HOST);
        }
        return $host ? 'https://' . $host . '/index.php' : '';
    }

    /**
     * Inline-button keyboard over getVideoQualities() +
     * getAudioFormats(): two buttons per row (Telegram renders these
     * nicely at that width), videos first then audios. Null when
     * neither produced a single resolvable link. Everything above
     * 360p is a video-only stream, so those buttons get a 🔇 marker.
     *
     * Buttons deliberately do NOT carry the resolved CDN URLs — each
     * googlevideo link is 800–1500 chars and a few of them blow past
     * Telegram's reply-markup size cap ("reply markup is too long").
     * They carry a short index.php?dl=1… redirect link keyed by the
     * tool77 cache id instead; index.php re-resolves the real URL at
     * tap time. That also makes button lifetime == tool77_cache_ttl.
     */
    private function buildYoutubeKeyboard(array $data, string $baseUrl, string $id): ?array
    {
        if ($baseUrl === '' || $id === '') {
            return null;
        }

        $videos = $this->tool77->getVideoQualities($data);
        $audios = $this->tool77->getAudioFormats($data);
        if (!$videos && !$audios) {
            return null;
        }

        $rows = [];
        $row = [];
        foreach ($videos as $height => $v) {
            $row[] = [
                'text' => "🎬 {$height}p" . ($v['hasAudio'] ? '' : ' 🔇'),
                'url'  => $baseUrl . '?dl=1&id=' . urlencode($id) . '&kind=video&h=' . (int) $height,
            ];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        foreach ($audios as $ext => $a) {
            $label = strtoupper($ext) . ($a['kbps'] > 0 ? " · {$a['kbps']}kbps" : '');
            $row[] = [
                'text' => "🎵 {$label}",
                'url'  => $baseUrl . '?dl=1&id=' . urlencode($id) . '&kind=audio&fmt=' . rawurlencode($ext),
            ];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        return ['inline_keyboard' => $rows];
    }

    private function handleTextSearch(int $chatId, int $userId, string $query): void
    {
        if (!$this->forceJoin->checkAll($userId)) {
            $this->storePendingRequest($userId, $chatId, $query);
            $this->forceJoin->sendJoinPrompt($chatId);
            return;
        }

        $this->telegram->sendChatAction($chatId, 'typing');
        $results = $this->ytSearch->search($query, 10);

        if (!$results['items']) {
            $this->telegram->sendMessage($chatId, "😕 No results found for that search.");
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "🔍 Results for *" . Validator::markdownEscape($query) . "*:",
            ['inline_keyboard' => $this->buildSearchKeyboard($results, $query)]
        );
    }

    /** @return array<array<array{text:string,callback_data:string}>> */
    private function buildSearchKeyboard(array $results, string $query): array
    {
        $keyboard = [];
        foreach ($results['items'] as $item) {
            if (!$item['videoId']) {
                continue;
            }
            $keyboard[] = [[
                'text'          => mb_substr($item['title'], 0, 60),
                'callback_data' => 'ytdl_' . $item['videoId'],
            ]];
        }
        if (!empty($results['nextPageToken'])) {
            $keyboard[] = [['text' => '➡️ Next', 'callback_data' => 'nextpage_' . $this->stashCallbackPayload(['q' => $query, 'pt' => $results['nextPageToken']])]];
        }
        return $keyboard;
    }

    /**
     * Telegram caps callback_data at 64 bytes — far too small for a
     * search query plus YouTube's pageToken (and long TikTok
     * usernames), which is what the pagination buttons need to carry.
     * So the payload goes into the `cache` table under a random key
     * and only the short key rides in the button. If the DB write
     * fails, falls back to inline base64, which still works whenever
     * the payload happens to fit the cap.
     */
    private function stashCallbackPayload(array $payload): string
    {
        try {
            $key = bin2hex(random_bytes(8));
            $pdo = Database::getInstance();
            $ttl = (int) Config::get('cache_ttl', 3600);
            $stmt = $pdo->prepare(
                'INSERT INTO cache (cache_key, cache_value, expires_at)
                 VALUES (:k, :v, DATE_ADD(NOW(), INTERVAL :ttl SECOND))'
            );
            $stmt->execute(['k' => 'cbpayload_' . $key, 'v' => json_encode($payload), 'ttl' => $ttl]);
            return $key;
        } catch (Throwable $e) {
            return base64_encode(json_encode($payload));
        }
    }

    /** Inverse of stashCallbackPayload(); accepts legacy inline-base64 payloads too. */
    private function popCallbackPayload(string $data): ?array
    {
        if (preg_match('/^[0-9a-f]{16}$/', $data)) {
            try {
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare('SELECT cache_value FROM cache WHERE cache_key = :k AND expires_at > NOW()');
                $stmt->execute(['k' => 'cbpayload_' . $data]);
                $row = $stmt->fetch();
                if ($row) {
                    return json_decode($row['cache_value'], true);
                }
            } catch (Throwable $e) {
                return null;
            }
            return null;
        }
        return json_decode(base64_decode($data), true) ?: null;
    }

    /** /username @handle — lists a TikTok user's recent videos to pick from. */
    public function handleUsernameLookup(int $chatId, int $userId, string $rawUsername): void
    {
        $uniqueId = ltrim(trim($rawUsername), '@');

        if (!$this->forceJoin->checkAll($userId)) {
            $this->storePendingRequest($userId, $chatId, '@' . $uniqueId);
            $this->forceJoin->sendJoinPrompt($chatId);
            return;
        }

        $this->telegram->sendChatAction($chatId, 'typing');
        $this->deliverUsernameResults($chatId, null, $uniqueId, 0);
    }

    /**
     * Shared by the initial lookup and the "Next" button — sends a new
     * message the first time, edits the existing one for pagination.
     */
    private function deliverUsernameResults(int $chatId, ?int $editMessageId, string $uniqueId, int $cursor): void
    {
        $result = $this->tiktokUser->fetchPosts($uniqueId, 12, $cursor);
        if (!$result || !$result['videos']) {
            $this->telegram->sendMessage($chatId, "😕 No videos found for @{$uniqueId} — the account may be private or not exist.");
            return;
        }

        $keyboard = [];
        foreach ($result['videos'] as $video) {
            $videoId = (string) ($video['video_id'] ?? $video['id'] ?? '');
            if ($videoId === '') {
                continue;
            }
            $keyboard[] = [['text' => $this->buildVideoLabel($video), 'callback_data' => 'tkuser_' . $videoId]];
        }

        if ($result['hasMore']) {
            $keyboard[] = [['text' => '➡️ Next', 'callback_data' => 'tkusernext_' . $this->stashCallbackPayload(['u' => $uniqueId, 'c' => $result['cursor']])]];
        }

        $text = "🎬 Recent videos from *@" . Validator::markdownEscape($uniqueId) . "*:";
        if ($editMessageId) {
            $this->telegram->editMessageText($chatId, $editMessageId, $text, ['inline_keyboard' => $keyboard]);
        } else {
            $this->telegram->sendMessage($chatId, $text, ['inline_keyboard' => $keyboard]);
        }
    }

    private function buildVideoLabel(array $video): string
    {
        $title = trim((string) ($video['title'] ?? ''));
        $label = $title !== '' ? mb_substr($title, 0, 45) : 'Video';

        $plays = (int) ($video['play_count'] ?? 0);
        if ($plays > 0) {
            $label .= ' · ' . $this->formatCount($plays) . ' plays';
        }
        return $label;
    }

    private function formatCount(int $n): string
    {
        if ($n >= 1000000) {
            return round($n / 1000000, 1) . 'M';
        }
        if ($n >= 1000) {
            return round($n / 1000, 1) . 'K';
        }
        return (string) $n;
    }

    public function handleCallback(array $callback): void
    {
        $callbackId = $callback['id'];
        $data = $callback['data'] ?? '';
        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;
        $userId = (int) ($callback['from']['id'] ?? 0);

        if (!$chatId || !$userId) {
            $this->telegram->answerCallbackQuery($callbackId);
            return;
        }

        if ($data === 'check_join') {
            $this->onCheckJoin($callbackId, (int) $chatId, (int) $messageId, $userId);
            return;
        }
        if (str_starts_with($data, 'nextpage_')) {
            $this->onNextPage($callbackId, (int) $chatId, (int) $messageId, substr($data, 9));
            return;
        }
        if (str_starts_with($data, 'ytdl_')) {
            $this->onYtDl($callbackId, (int) $chatId, (int) $messageId, $userId, substr($data, 5));
            return;
        }
        if (str_starts_with($data, 'dlaud_')) {
            $this->onDownloadAudioButton($callbackId, (int) $chatId, $userId, substr($data, 6));
            return;
        }
        if (str_starts_with($data, 'tkaud_')) {
            $this->onTikTokAudioButton($callbackId, (int) $chatId, $userId, substr($data, 6));
            return;
        }
        if (str_starts_with($data, 'tkusernext_')) {
            $this->onTikTokUserNextPage($callbackId, (int) $chatId, (int) $messageId, substr($data, 11));
            return;
        }
        if (str_starts_with($data, 'tkuser_')) {
            $this->onTikTokUserVideoSelected($callbackId, (int) $chatId, (int) $messageId, $userId, substr($data, 7));
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
    }

    private function onCheckJoin(string $callbackId, int $chatId, int $messageId, int $userId): void
    {
        // The not-joined alert must be the FIRST (and only) answer to
        // this callback query — Telegram rejects any second
        // answerCallbackQuery on an already-answered query ID, so
        // answering unconditionally up front would silently swallow it.
        if (!$this->forceJoin->checkAll($userId)) {
            $this->telegram->answerCallbackQuery($callbackId, "You haven't joined all channels yet.", true);
            return;
        }
        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->deleteMessage($chatId, $messageId);
        $this->resumePendingRequest($userId, $chatId);
    }

    private function onNextPage(string $callbackId, int $chatId, int $messageId, string $encodedPayload): void
    {
        $this->telegram->answerCallbackQuery($callbackId);
        $payload = $this->popCallbackPayload($encodedPayload);
        if (!$payload || empty($payload['q'])) {
            return;
        }

        $results = $this->ytSearch->search($payload['q'], 10, $payload['pt'] ?? null);
        $this->telegram->editMessageText(
            $chatId,
            $messageId,
            "🔍 Results for *" . Validator::markdownEscape($payload['q']) . "*:",
            ['inline_keyboard' => $this->buildSearchKeyboard($results, $payload['q'])]
        );
    }

    private function onYtDl(string $callbackId, int $chatId, int $messageId, int $userId, string $videoId): void
    {
        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
        $this->handleYouTubeUrl($chatId, $userId, 'https://www.youtube.com/watch?v=' . $videoId);
    }

    private function onTikTokUserNextPage(string $callbackId, int $chatId, int $messageId, string $encodedPayload): void
    {
        $this->telegram->answerCallbackQuery($callbackId);
        $payload = $this->popCallbackPayload($encodedPayload);
        if (!$payload || empty($payload['u'])) {
            return;
        }
        $this->deliverUsernameResults($chatId, $messageId, (string) $payload['u'], (int) ($payload['c'] ?? 0));
    }

    /** Reconstructs the canonical TikTok URL and hands off to handleTikTokUrl() — same delivery pipeline as any other TikTok link. */
    private function onTikTokUserVideoSelected(string $callbackId, int $chatId, int $messageId, int $userId, string $videoId): void
    {
        $video = $this->tiktokUser->getCachedVideo($videoId);
        $uniqueId = (string) ($video['author']['unique_id'] ?? '');
        if (!$video || $uniqueId === '') {
            $this->telegram->answerCallbackQuery($callbackId, "That expired — try /username again.", true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
        $this->handleTikTokUrl($chatId, $userId, "https://www.tiktok.com/@{$uniqueId}/video/{$videoId}");
    }

    /** "Download Audio" button under Facebook results — cached via Tool77Service. */
    private function onDownloadAudioButton(string $callbackId, int $chatId, int $userId, string $id): void
    {
        $data = $this->tool77->getCachedById($id);
        if (!$data) {
            $this->telegram->answerCallbackQuery($callbackId, "That link expired — please resend it.", true);
            return;
        }

        $audio = $this->tool77->getBestAudio($data);
        $audioUrl = $audio ? $this->tool77->resolveUrl($audio) : null;
        if (!$audioUrl) {
            $this->telegram->answerCallbackQuery($callbackId, "No audio track available.", true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendChatAction($chatId, 'upload_audio');
        $this->telegram->sendAudio($chatId, $audioUrl, '🎵 Extracted audio');

        $this->saveDownload($userId, (string) ($data['originUrl'] ?? $id), 'facebook_audio');
        $this->stats->recordDownload();
        $this->ads->maybeShow($chatId);
    }

    /** "Download Audio" button under TikTok results — audio URL pre-cached by handleTikTokUrl() via TikwmService. */
    private function onTikTokAudioButton(string $callbackId, int $chatId, int $userId, string $tiktokId): void
    {
        $cached = $this->tikwm->getCachedAudio($tiktokId);
        if (!$cached || empty($cached['url'])) {
            $this->telegram->answerCallbackQuery($callbackId, "That link expired — please resend it.", true);
            return;
        }

        $this->telegram->answerCallbackQuery($callbackId);
        $this->telegram->sendChatAction($chatId, 'upload_audio');
        $this->telegram->sendAudio($chatId, $cached['url'], '🎵 Extracted audio');

        $this->saveDownload($userId, (string) ($cached['origin'] ?? ('tkaud_' . $tiktokId)), 'tiktok_audio');
        $this->stats->recordDownload();
        $this->ads->maybeShow($chatId);
    }

    private function storePendingRequest(int $userId, int $chatId, string $url): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO pending_requests (user_id, chat_id, url) VALUES (:u, :c, :url)
             ON DUPLICATE KEY UPDATE url = VALUES(url), chat_id = VALUES(chat_id)'
        );
        $stmt->execute(['u' => $userId, 'c' => $chatId, 'url' => $url]);
    }

    private function resumePendingRequest(int $userId, int $chatId): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM pending_requests WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $pending = $stmt->fetch();
        if (!$pending) {
            return;
        }

        $pdo->prepare('DELETE FROM pending_requests WHERE user_id = :u')->execute(['u' => $userId]);

        $url = $pending['url'];
        if (Validator::isTikTokUrl($url)) {
            $this->handleTikTokUrl($chatId, $userId, $url);
        } elseif (Validator::isFacebookUrl($url)) {
            $this->handleFacebookUrl($chatId, $userId, $url);
        } elseif (Validator::isYouTubeUrl($url)) {
            $this->handleYouTubeUrl($chatId, $userId, $url);
        } elseif (str_starts_with($url, '@')) {
            $this->handleUsernameLookup($chatId, $userId, $url);
        } else {
            $this->handleTextSearch($chatId, $userId, $url);
        }
    }

    private function saveDownload(int $userId, string $url, string $type): void
    {
        Download::record($userId, $url, $type);
    }
}
