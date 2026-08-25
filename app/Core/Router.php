<?php

namespace App\Core;

use App\Controllers\BotController;
use App\Helpers\Logger;

/**
 * Entry point for Telegram's webhook POST. Decodes the update and
 * hands it to BotController.
 */
class Router
{
    public function handleWebhook(): void
    {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true);

        if (!is_array($update)) {
            // Usually scanners hitting the webhook URL — logged at info
            // so real misconfigurations (wrong secret, wrong endpoint)
            // are still visible in app.log without drowning in errors.
            Logger::write('info', 'Webhook received a non-JSON POST body — rejected with 400', [
                'body' => mb_substr((string) $input, 0, 200),
            ]);
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
