<?php

namespace App\Services;

use App\Models\User;

/**
 * Sends a message to every non-banned user (/broadcast admin command).
 * Throttled to stay under Telegram's ~30 msg/sec global rate limit.
 */
class BroadcastService
{
    private TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    /** @return array{success:int,failed:int,total:int} */
    public function send(string $message): array
    {
        $ids = User::allActiveTelegramIds();
        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $result = $this->telegram->sendMessage($id, $message);
            if ($result && ($result['ok'] ?? false)) {
                $success++;
            } else {
                $failed++;
            }
            usleep(50000); // ~20 messages/sec
        }

        return ['success' => $success, 'failed' => $failed, 'total' => count($ids)];
    }

    /**
     * Broadcasts an existing message (any content type — photo,
     * video, text, whatever was forwarded) to every non-banned user by
     * copying it out of the admin's chat with the bot. Same throttle
     * as send() — Telegram's ~30 msg/sec global limit applies equally.
     *
     * @return array{success:int,failed:int,total:int}
     */
    public function sendForwarded(int $fromChatId, int $fromMessageId): array
    {
        $ids = User::allActiveTelegramIds();
        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $result = $this->telegram->copyMessage($id, $fromChatId, $fromMessageId);
            if ($result && ($result['ok'] ?? false)) {
                $success++;
            } else {
                $failed++;
            }
            usleep(50000); // ~20 messages/sec
        }

        return ['success' => $success, 'failed' => $failed, 'total' => count($ids)];
    }
}
