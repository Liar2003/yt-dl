<?php
/**
 * One-off CLI helper: run `php bin/set-webhook.php` on the server
 * (or locally with the same config) to register the webhook URL from
 * config/config.php with Telegram.
 *
 * Equivalent curl command, if you prefer:
 *   curl -F "url=https://yourdomain.com/public/webhook.php" \
 *        https://api.telegram.org/bot<TOKEN>/setWebhook
 */

require_once __DIR__ . '/../app/autoload.php';

use App\Core\Config;
use App\Services\TelegramService;

$telegram = new TelegramService();
$result = $telegram->setWebhook(Config::get('webhook_url'));

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
