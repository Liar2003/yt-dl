<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Setting;

/**
 * Shows one random stored ad after a successful download, when the
 * ads_enabled setting is on. Adding an ad happens elsewhere
 * (BotController detects an admin forwarding a message and calls
 * Ad::create() directly) — this service only handles the "show" side.
 */
class AdsService
{
    private TelegramService $telegram;

    public function __construct()
    {
        $this->telegram = new TelegramService();
    }

    public function isEnabled(): bool
    {
        return Setting::isTrue('ads_enabled', false);
    }

    /** No-op if ads are off or none are stored — safe to call unconditionally after every successful delivery. */
    public function maybeShow(int $chatId): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $ad = Ad::random();
        if (!$ad) {
            return;
        }
        // A failure here (e.g. the admin deleted the source message)
        // is logged by TelegramService's request() and otherwise
        // ignored — a broken ad should never disrupt someone's
        // download.
        $this->telegram->copyMessage($chatId, (int) $ad['source_chat_id'], (int) $ad['source_message_id']);
    }
}
