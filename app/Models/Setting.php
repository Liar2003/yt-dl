<?php

namespace App\Models;

use App\Core\Database;

/**
 * Key/value feature toggles (force_join_enabled, maintenance_mode, ...).
 */
class Setting
{
    public static function get(string $key, $default = null)
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    }

    public static function set(string $key, $value): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['k' => $key, 'v' => $value]);
    }

    public static function isTrue(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default ? '1' : '0');
        return $val === '1' || $val === 1 || $val === true;
    }
}
