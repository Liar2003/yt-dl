<?php

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Daily rollup counters backing /admin and /stats. recordDownload()
 * and recordNewUser() are called from BotController as events happen.
 * Queries use SQLite-compatible syntax: CURDATE() -> DATE('now'),
 * DATE_SUB -> datetime('now', ...), ON DUPLICATE KEY UPDATE -> ON
 * CONFLICT DO UPDATE.
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
            "SELECT stat_date, downloads_count, new_users_count FROM statistics
             WHERE stat_date >= DATE('now', :days) ORDER BY stat_date ASC"
        );
        $stmt->bindValue(':days', '-' . $days . ' days', PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recordDownload(): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO statistics (stat_date, downloads_count, new_users_count) VALUES (DATE('now'), 1, 0)
             ON CONFLICT(stat_date) DO UPDATE SET downloads_count = downloads_count + 1"
        );
        $stmt->execute();
    }

    public function recordNewUser(): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO statistics (stat_date, downloads_count, new_users_count) VALUES (DATE('now'), 0, 1)
             ON CONFLICT(stat_date) DO UPDATE SET new_users_count = new_users_count + 1"
        );
        $stmt->execute();
    }
}
