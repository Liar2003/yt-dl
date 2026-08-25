<?php

namespace App\Models;

use App\Core\Database;

/**
 * Shared by BotController and bin/poll-youtube.php so both write to
 * the `downloads` log the same way.
 */
class Download
{
    public static function record(int $userId, string $url, string $type): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('INSERT INTO downloads (user_id, url, type) VALUES (:u, :url, :type)');
        $stmt->execute(['u' => $userId, 'url' => $url, 'type' => $type]);
    }

    /** Total completed downloads for one user — powers the /start greeting counter. */
    public static function countForUser(int $userId): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM downloads WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
