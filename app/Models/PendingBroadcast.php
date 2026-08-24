<?php

namespace App\Models;

use App\Core\Database;
use PDO;

/**
 * Tracks which admin just ran /forward and is "armed" to have their
 * next forwarded message broadcast to everyone, instead of that
 * forward being auto-captured as an ad (see BotController's forward
 * detection — both features are triggered by an admin forwarding a
 * message, this is what tells them apart).
 */
class PendingBroadcast
{
    public static function set(int $adminId): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO pending_broadcasts (admin_id) VALUES (:id) ON DUPLICATE KEY UPDATE created_at = NOW()'
        );
        $stmt->execute(['id' => $adminId]);
    }

    /** Also true only within $maxAgeSeconds of /forward being run, so a forward days later isn't mistaken for a stale intent. */
    public static function isPending(int $adminId, int $maxAgeSeconds = 600): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT 1 FROM pending_broadcasts WHERE admin_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL :age SECOND)'
        );
        $stmt->bindValue(':id', $adminId, PDO::PARAM_INT);
        $stmt->bindValue(':age', $maxAgeSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    public static function clear(int $adminId): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM pending_broadcasts WHERE admin_id = :id')->execute(['id' => $adminId]);
    }
}
