<?php

namespace App\Controllers;

use App\Core\Database;
use App\Helpers\Validator;
use App\Models\Ad;
use App\Models\Log;
use App\Models\PendingBroadcast;
use App\Models\Setting;
use App\Models\User;
use App\Services\BroadcastService;
use App\Services\StatisticsService;
use App\Services\TelegramService;

/**
 * Admin-only commands, dispatched from BotController::handleCommand()
 * after User::isAdmin() passes. Adding an ad is NOT a command here —
 * an admin forwards any message to the bot and BotController stores
 * it automatically (see BotController::handleAdForward()).
 */
class AdminController
{
    private TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function handleCommand(string $text, int $chatId, int $adminId): void
    {
        $parts = preg_split('/\s+/', trim($text));
        $command = strtolower(array_shift($parts));

        switch ($command) {
            case '/admin':        $this->dashboard($chatId); break;
            case '/users':        $this->listUsers($chatId); break;
            case '/ban':          $this->banUser($chatId, $parts, true); break;
            case '/unban':        $this->banUser($chatId, $parts, false); break;
            case '/history':      $this->history($chatId, $parts); break;
            case '/list':         $this->listDownloads($chatId, $parts); break;
            case '/forcejoin':    $this->toggleForceJoin($chatId, $parts); break;
            case '/addchannel':   $this->addChannel($chatId, $parts); break;
            case '/removechannel': $this->removeChannel($chatId, $parts); break;
            case '/channels':     $this->listChannels($chatId); break;
            case '/broadcast':    $this->broadcast($chatId, $parts); break;
            case '/forward':      $this->startForwardBroadcast($chatId, $adminId); break;
            case '/stats':        $this->statsCommand($chatId); break;
            case '/logs':         $this->logsCommand($chatId); break;
            case '/maintenance':  $this->toggleMaintenance($chatId, $parts); break;
            case '/addadmin':     $this->addAdmin($chatId, $parts); break;
            case '/removeadmin':  $this->removeAdmin($chatId, $parts); break;
            case '/admins':       $this->listAdmins($chatId); break;
            case '/ads':          $this->toggleAds($chatId, $parts); break;
            case '/adslist':      $this->listAds($chatId); break;
            case '/adsremove':    $this->removeAd($chatId, $parts); break;
            case '/top':          $this->topUsers($chatId); break;
            case '/errors':       $this->errorsCommand($chatId); break;
            default:
                $this->telegram->sendMessage($chatId, "Unknown admin command. Try /admin for the dashboard.");
        }
    }

    private function dashboard(int $chatId): void
    {
        $stats = new StatisticsService();
        $text = "🛠 *Admin Dashboard*\n\n" .
            "👥 Users: " . $stats->totalUsers() . "\n" .
            "⬇️ Downloads: " . $stats->totalDownloads() . "\n" .
            "📢 Ads stored: " . Ad::count() . "\n" .
            "🔒 Force join: " . (Setting::isTrue('force_join_enabled') ? 'ON' : 'OFF') . "\n" .
            "📣 Ads: " . (Setting::isTrue('ads_enabled') ? 'ON' : 'OFF') . "\n" .
            "🚧 Maintenance: " . (Setting::isTrue('maintenance_mode') ? 'ON' : 'OFF') . "\n\n" .
            "/users /ban /unban /history /list /top\n" .
            "/forcejoin /addchannel /removechannel /channels\n" .
            "/broadcast /forward /stats /logs /errors\n" .
            "/addadmin /removeadmin /admins\n" .
            "/ads /adslist /adsremove — forward any message to add one\n" .
            "/maintenance";
        $this->telegram->sendMessage($chatId, $text);
    }

    private function listUsers(int $chatId): void
    {
        $pdo = Database::getInstance();
        $rows = $pdo->query('SELECT telegram_id, username, is_banned FROM users ORDER BY id DESC LIMIT 30')->fetchAll();
        $lines = array_map(
            fn($r) => ($r['is_banned'] ? '🚫' : '✅') . " `{$r['telegram_id']}` @" . ($r['username'] ?: '-'),
            $rows
        );
        $this->telegram->sendMessage($chatId, "*Recent users (max 30):*\n" . implode("\n", $lines ?: ['No users yet.']));
    }

    private function banUser(int $chatId, array $args, bool $ban): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /" . ($ban ? 'ban' : 'unban') . " <telegram_id>");
            return;
        }
        User::setBanned((int) $args[0], $ban);
        $this->telegram->sendMessage($chatId, ($ban ? '🚫 Banned' : '✅ Unbanned') . " user `{$args[0]}`.");
    }

    private function history(int $chatId, array $args): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /history <telegram_id>");
            return;
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT url, type, created_at FROM downloads WHERE user_id = :u ORDER BY id DESC LIMIT 15');
        $stmt->execute(['u' => $args[0]]);
        $lines = array_map(fn($r) => "[{$r['type']}] {$r['created_at']}", $stmt->fetchAll());
        $this->telegram->sendMessage($chatId, "*Download history for `{$args[0]}` (max 15):*\n" . implode("\n", $lines ?: ['No downloads yet.']));
    }

    /** Like /history, but shows the actual links instead of just type + timestamp. */
    private function listDownloads(int $chatId, array $args): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /list <telegram_id>");
            return;
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT url, type, created_at FROM downloads WHERE user_id = :u ORDER BY id DESC LIMIT 20');
        $stmt->execute(['u' => $args[0]]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            $this->telegram->sendMessage($chatId, "No downloads yet for `{$args[0]}`.");
            return;
        }

        $lines = array_map(function ($r) {
            $url = (string) $r['url'];
            $short = mb_strlen($url) > 70 ? mb_substr($url, 0, 70) . '…' : $url;
            // Raw URLs routinely contain _ * ` — Telegram's legacy
            // Markdown treats those as formatting characters, so an
            // unescaped link here can silently mangle the message or
            // make Telegram reject it outright as unparseable.
            return "[{$r['type']}] " . Validator::markdownEscape($short);
        }, $rows);

        $this->telegram->sendMessage(
            $chatId,
            "*Downloaded links for `{$args[0]}` (max 20):*\n" . implode("\n", $lines)
        );
    }

    private function toggleForceJoin(int $chatId, array $args): void
    {
        $state = strtolower($args[0] ?? '');
        if (!in_array($state, ['on', 'off'], true)) {
            $this->telegram->sendMessage($chatId, "Usage: /forcejoin on|off");
            return;
        }
        Setting::set('force_join_enabled', $state === 'on' ? '1' : '0');
        $this->telegram->sendMessage($chatId, "Force join turned " . strtoupper($state) . ".");
    }

    private function addChannel(int $chatId, array $args): void
    {
        if (empty($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /addchannel @channelusername [Optional Title]");
            return;
        }
        $username = '@' . ltrim($args[0], '@');
        $title = implode(' ', array_slice($args, 1)) ?: null;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('INSERT INTO required_channels (channel_username, channel_title) VALUES (:u, :t)');
        $stmt->execute(['u' => $username, 't' => $title]);
        $this->telegram->sendMessage($chatId, "✅ Added channel {$username}.\n\nMake sure the bot is an admin in that channel so it can check membership.");
    }

    private function removeChannel(int $chatId, array $args): void
    {
        if (empty($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /removechannel @channelusername");
            return;
        }
        $username = '@' . ltrim($args[0], '@');
        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM required_channels WHERE channel_username = :u')->execute(['u' => $username]);
        $this->telegram->sendMessage($chatId, "🗑 Removed channel {$username}.");
    }

    private function listChannels(int $chatId): void
    {
        $pdo = Database::getInstance();
        $rows = $pdo->query('SELECT channel_username, channel_title FROM required_channels')->fetchAll();
        $lines = array_map(
            fn($r) => $r['channel_username'] . ($r['channel_title'] ? " ({$r['channel_title']})" : ''),
            $rows
        );
        $this->telegram->sendMessage($chatId, "*Required channels:*\n" . implode("\n", $lines ?: ['None set.']));
    }

    private function broadcast(int $chatId, array $args): void
    {
        $message = implode(' ', $args);
        if ($message === '') {
            $this->telegram->sendMessage($chatId, "Usage: /broadcast <message>");
            return;
        }
        $this->telegram->sendMessage($chatId, "📣 Broadcasting to all active users…");
        $result = (new BroadcastService())->send($message);
        $this->telegram->sendMessage(
            $chatId,
            "✅ Sent to {$result['success']}/{$result['total']} users ({$result['failed']} failed)."
        );
    }

    /**
     * Arms the admin for /forward — the actual broadcast happens when
     * BotController sees their next forwarded message (see
     * PendingBroadcast and BotController::handleBroadcastForward()).
     * A slash command can't carry a forward in the same update, so
     * this two-step is what lets /forward broadcast any content type
     * (photo, video, poll, whatever) instead of /broadcast's plain
     * text only.
     */
    private function startForwardBroadcast(int $chatId, int $adminId): void
    {
        PendingBroadcast::set($adminId);
        $this->telegram->sendMessage(
            $chatId,
            "📤 Now forward me the message you want to broadcast to all users. (Expires in 10 minutes.)"
        );
    }

    private function statsCommand(int $chatId): void
    {
        $days = (new StatisticsService())->getLastDays(7);
        $lines = array_map(
            fn($r) => "{$r['stat_date']}: {$r['downloads_count']} downloads, {$r['new_users_count']} new users",
            $days
        );
        $this->telegram->sendMessage($chatId, "*Last 7 days:*\n" . implode("\n", $lines ?: ['No data yet.']));
    }

    private function logsCommand(int $chatId): void
    {
        $lines = array_map(
            fn($r) => "[{$r['level']}] {$r['created_at']}: " . mb_substr($r['message'], 0, 80),
            Log::recent(15)
        );
        $this->telegram->sendMessage($chatId, "*Recent logs (max 15):*\n" . implode("\n", $lines ?: ['No logs yet.']));
    }

    private function errorsCommand(int $chatId): void
    {
        $lines = array_map(
            fn($r) => "{$r['created_at']}: " . mb_substr($r['message'], 0, 100),
            Log::recentByLevel('error', 15)
        );
        $this->telegram->sendMessage($chatId, "*Recent errors (max 15):*\n" . implode("\n", $lines ?: ['No errors logged. 🎉']));
    }

    private function toggleMaintenance(int $chatId, array $args): void
    {
        $state = strtolower($args[0] ?? '');
        if (!in_array($state, ['on', 'off'], true)) {
            $this->telegram->sendMessage($chatId, "Usage: /maintenance on|off");
            return;
        }
        Setting::set('maintenance_mode', $state === 'on' ? '1' : '0');
        $this->telegram->sendMessage($chatId, "Maintenance mode turned " . strtoupper($state) . ".");
    }

    private function addAdmin(int $chatId, array $args): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /addadmin <telegram_id>");
            return;
        }
        User::addAdmin((int) $args[0]);
        $this->telegram->sendMessage($chatId, "✅ `{$args[0]}` can now use admin commands.");
    }

    private function removeAdmin(int $chatId, array $args): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /removeadmin <telegram_id>");
            return;
        }
        $removed = User::removeAdmin((int) $args[0]);
        $this->telegram->sendMessage(
            $chatId,
            $removed
                ? "🗑 Removed `{$args[0]}` from admins."
                : "That ID wasn't in the admins table (the bootstrap admin_telegram_id in config.php can't be removed this way)."
        );
    }

    private function listAdmins(int $chatId): void
    {
        $rows = User::listAdmins();
        $lines = array_map(fn($r) => "`{$r['telegram_id']}` — added {$r['added_at']}", $rows);
        $this->telegram->sendMessage(
            $chatId,
            "*Admins (from the admins table):*\n" . implode("\n", $lines ?: ['None added yet.']) .
            "\n\nThe bootstrap admin_telegram_id from config.php always has access too, even if not listed here."
        );
    }

    private function toggleAds(int $chatId, array $args): void
    {
        $state = strtolower($args[0] ?? '');
        if (!in_array($state, ['on', 'off'], true)) {
            $this->telegram->sendMessage($chatId, "Usage: /ads on|off\n\nTo add an ad, just forward any message to the bot.");
            return;
        }
        Setting::set('ads_enabled', $state === 'on' ? '1' : '0');
        $this->telegram->sendMessage($chatId, "Ads turned " . strtoupper($state) . ".");
    }

    private function listAds(int $chatId): void
    {
        $rows = Ad::all();
        $lines = array_map(fn($r) => "#{$r['id']} — added {$r['created_at']}", $rows);
        $this->telegram->sendMessage($chatId, "*Stored ads (" . count($rows) . "):*\n" . implode("\n", $lines ?: ['None yet — forward a message to add one.']));
    }

    private function removeAd(int $chatId, array $args): void
    {
        if (empty($args[0]) || !ctype_digit($args[0])) {
            $this->telegram->sendMessage($chatId, "Usage: /adsremove <id> — see IDs with /adslist");
            return;
        }
        $removed = Ad::delete((int) $args[0]);
        $this->telegram->sendMessage($chatId, $removed ? "🗑 Removed ad #{$args[0]}." : "No ad with that ID.");
    }

    private function topUsers(int $chatId): void
    {
        $pdo = Database::getInstance();
        $rows = $pdo->query(
            'SELECT u.telegram_id, u.username, COUNT(d.id) AS downloads
             FROM downloads d
             JOIN users u ON u.telegram_id = d.user_id
             GROUP BY u.telegram_id, u.username
             ORDER BY downloads DESC
             LIMIT 10'
        )->fetchAll();
        $lines = array_map(
            fn($r) => "{$r['downloads']}× — `{$r['telegram_id']}` @" . ($r['username'] ?: '-'),
            $rows
        );
        $this->telegram->sendMessage($chatId, "*Top downloaders:*\n" . implode("\n", $lines ?: ['No downloads yet.']));
    }
}
