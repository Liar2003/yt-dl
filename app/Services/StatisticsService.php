<?php

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Daily rollup counters backing /admin and /stats. recordDownload()
 * and recordNewUser() are called from BotController as events happen.
 */
class StatisticsService
{
    public function totalDownloads(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) FROM downloads')->fetchColumn();
    }

    public function totalUsers(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return array<array{stat_date:string,downloads_count:int,new_users_count:int}> */
    public function getLastDays(int $days = 7): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT stat_date, downloads_count, new_users_count FROM statistics
             WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) ORDER BY stat_date ASC'
        );
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recordDownload(): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO statistics (stat_date, downloads_count, new_users_count) VALUES (CURDATE(), 1, 0)
             ON DUPLICATE KEY UPDATE downloads_count = downloads_count + 1'
        );
        $stmt->execute();
    }

    public function recordNewUser(): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO statistics (stat_date, downloads_count, new_users_count) VALUES (CURDATE(), 0, 1)
             ON DUPLICATE KEY UPDATE new_users_count = new_users_count + 1'
        );
        $stmt->execute();
    }
}
