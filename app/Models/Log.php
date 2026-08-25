<?php

namespace App\Models;

use App\Core\Database;
use PDO;

class Log
{
    /** Most recent entries of one level ('info' | 'warning' | 'error') — backs /errors. */
    public static function recentByLevel(string $level, int $limit = 50): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM logs WHERE level = :level ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':level', $level);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
