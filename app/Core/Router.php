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
