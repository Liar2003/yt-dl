<?php

namespace App\Core;

use App\Controllers\BotController;
use App\Helpers\Logger;

/**
 * Entry point for Telegram's webhook POST. Verifies the optional
 * secret token, decodes the update, and hands it to BotController.
 */
class Router
{
    public function handleWebhook(): void
    {
        // Telegram echoes back the secret_token given to setWebhook in
        // this header on every delivery. Only enforced when a secret is
        // actually configured, so a bare install keeps working.
        $secret = Config::get('webhook_secret');
        if ($secret && ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== $secret) {
            http_response_code(403);
            return;
        }

        $input = file_get_contents('php://input');
        $update = json_decode($input, true);

        if (!is_array($update)) {
            http_response_code(400);
            return;
        }

        try {
            (new BotController())->processUpdate($update);
        } catch (\Throwable $e) {
            Logger::write('error', 'Unhandled router exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        http_response_code(200);
    }
}
