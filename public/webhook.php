<?php
/**
 * Telegram webhook entry point. Point BotFather's setWebhook (or the
 * bin/set-webhook.php helper) at this file's public HTTPS URL.
 */

require_once __DIR__ . '/../app/autoload.php';

use App\Core\Router;

(new Router())->handleWebhook();
