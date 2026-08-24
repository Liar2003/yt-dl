<?php

namespace App\Models;

use App\Core\Config;
use App\Core\Database;
use PDO;

class User
{
    /**
     * Inserts a user on first contact, otherwise refreshes their
     * profile fields and last-active timestamp. Returns the row.
     */
    public static function registerOrUpdate(array $tgUser): array
    {
        $pdo = Database::getInstance();

        $select = $pdo->prepare('SELECT * FROM users WHERE telegram_id = :id');
        $select->execute(['id' => $tgUser['id']]);
        $existing = $select->fetch();

        if ($existing) {
            $update = $pdo->prepare(
                'UPDATE users SET username = :username, first_name = :first_name,
                 last_name = :last_name, last_active_at = NOW() WHERE telegram_id = :id'
            );
            $update->execute([
                'username'   => $tgUser['username'] ?? null,
                'first_name' => $tgUser['first_name'] ?? null,
                'last_name'  => $tgUser['last_name'] ?? null,
                'id'         => $tgUser['id'],
            ]);
            return self::findByTelegramId((int) $tgUser['id']) ?? $existing;
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (telegram_id, username, first_name, last_name)
             VALUES (:id, :username, :first_name, :last_name)'
        );
        $insert->execute([
            'id'         => $tgUser['id'],
            'username'   => $tgUser['username'] ?? null,
            'first_name' => $tgUser['first_name'] ?? null,
            'last_name'  => $tgUser['last_name'] ?? null,
        ]);

        return self::findByTelegramId((int) $tgUser['id']);
    }

    public static function findByTelegramId(int $telegramId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = :id');
        $stmt->execute(['id' => $telegramId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isBanned(int $telegramId): bool
    {
        $user = self::findByTelegramId($telegramId);
        return $user ? (bool) $user['is_banned'] : false;
    }

    public static function setBanned(int $telegramId, bool $banned): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE users SET is_banned = :b WHERE telegram_id = :id');
        $stmt->execute(['b' => $banned ? 1 : 0, 'id' => $telegramId]);
    }

    /**
     * True for the bootstrap admin in config.php OR anyone in the
     * `admins` table.
     */
    public static function isAdmin(int $telegramId): bool
    {
        if ($telegramId === (int) Config::get('admin_telegram_id')) {
            return true;
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT 1 FROM admins WHERE telegram_id = :id');
        $stmt->execute(['id' => $telegramId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function countActive(): int
    {
        $pdo = Database::getInstance();
        return (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_banned = 0')->fetchColumn();
    }

    public static function addAdmin(int $telegramId): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO admins (telegram_id) VALUES (:id)
             ON DUPLICATE KEY UPDATE telegram_id = telegram_id'
        );
        $stmt->execute(['id' => $telegramId]);
    }

    /** @return bool True if a row was deleted; false means the ID wasn't in the admins table. */
    public static function removeAdmin(int $telegramId): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM admins WHERE telegram_id = :id');
        $stmt->execute(['id' => $telegramId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<array{telegram_id:string,added_at:string}> */
    public static function listAdmins(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query('SELECT telegram_id, added_at FROM admins ORDER BY id ASC')->fetchAll();
    }

    /** @return int[] */
    public static function allActiveTelegramIds(): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query('SELECT telegram_id FROM users WHERE is_banned = 0');
        return array_map('intval', array_column($stmt->fetchAll(), 'telegram_id'));
    }
}
