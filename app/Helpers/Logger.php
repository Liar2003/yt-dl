<?php

namespace App\Helpers;

use App\Core\Config;
use App\Core\Database;
use Throwable;

/**
 * Writes to both the flat log file and the `logs` table. The file
 * write happens first so a DB outage never means total silence.
 */
class Logger
{
    public static function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            '[%s] [%s] %s %s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context) : ''
        );

        $logFile = Config::get('log_file');
        if ($logFile) {
            @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare(
                'INSERT INTO logs (level, message, context) VALUES (:level, :message, :context)'
            );
            $stmt->execute([
                'level'   => $level,
                'message' => $message,
                'context' => $context ? json_encode($context) : null,
            ]);
        } catch (Throwable $e) {
            // Best effort — the file log above already has it.
        }
    }
}
