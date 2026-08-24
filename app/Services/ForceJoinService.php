<?php

namespace App\Services;

use App\Core\Database;
use App\Models\Setting;

/**
 * "Force join" — require membership in one or more channels before a
 * user can download anything.
 */
class ForceJoinService
{
    private TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function isEnabled(): bool
    {
        return Setting::isTrue('force_join_enabled', false);
    }

    /** @return array<array{id:int,channel_username:string,channel_title:?string}> */
    public function getChannels(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query('SELECT * FROM required_channels ORDER BY id ASC')->fetchAll();
    }

    /** True if force-join is off, no channels are configured, or the user has joined all of them. */
    public function checkAll(int $userId): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        $channels = $this->getChannels();
        if (!$channels) {
            return true;
        }

        foreach ($channels as $channel) {
            $response = $this->telegram->getChatMember($channel['channel_username'], $userId);
            $status = $response['result']['status'] ?? null;
            if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
                return false;
            }
        }

        return true;
    }

    public function sendJoinPrompt(int $chatId): void
    {
        $keyboard = [];
        foreach ($this->getChannels() as $channel) {
            $keyboard[] = [[
                'text' => '📢 ' . ($channel['channel_title'] ?: $channel['channel_username']),
                'url'  => 'https://t.me/' . ltrim($channel['channel_username'], '@'),
            ]];
        }
        $keyboard[] = [['text' => '✅ Check Again', 'callback_data' => 'check_join']];

        $this->telegram->sendMessage(
            $chatId,
            "🔒 *Join required*\n\nPlease join the channel(s) below, then tap *Check Again*.",
            ['inline_keyboard' => $keyboard]
        );
    }
}
