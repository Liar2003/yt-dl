<?php

namespace App\Core;

/**
 * Loads config/config.php once and exposes values by dot-notation,
 * e.g. Config::get('db.host').
 */
class Config
{
    private static ?array $data = null;

    private static function load(): array
    {
        if (self::$data === null) {
            self::$data = require __DIR__ . '/../../config/config.php';
        }
        return self::$data;
    }

    public static function get(string $key, $default = null)
    {
        $value = self::load();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
