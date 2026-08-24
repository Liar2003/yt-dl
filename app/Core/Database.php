<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Single shared PDO connection, created lazily on first use.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = Config::get('db', []);
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $cfg['host'] ?? 'localhost',
                $cfg['dbname'] ?? '',
                $cfg['charset'] ?? 'utf8mb4'
            );

            try {
                self::$instance = new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // Logger also writes to a flat file, so a DB outage is
                // still visible even though the DB-backed log can't help.
                error_log('[DB] connection failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$instance;
    }
}
