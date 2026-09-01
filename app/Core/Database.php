<?php

namespace App\Core;

use PDO;
use PDOException;

/**
 * Single shared PDO connection, created lazily on first use.
 * Driver is chosen by config 'db.driver' — 'sqlite' (default) or 'mysql'.
 * All query call sites in the app stay on PDO, so this is the only
 * place the driver actually matters.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = Config::get('db', []);
            $driver = strtolower((string) ($cfg['driver'] ?? 'sqlite'));

            try {
                self::$instance = $driver === 'mysql'
                    ? self::makeMysql($cfg)
                    : self::makeSqlite($cfg);
            } catch (PDOException $e) {
                // Logger also writes to a flat file, so a DB outage is
                // still visible even though the DB-backed log can't help.
                error_log('[DB] connection failed: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$instance;
    }

    private static function makeSqlite(array $cfg): PDO
    {
        $path = $cfg['sqlite_path'] ?? (__DIR__ . '/../../database/app.db');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Foreign keys are off by default in SQLite; the schema turns
            // them on per-connection too, but this covers ad-hoc sessions.
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }

    private static function makeMysql(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;username=%s;password=%s;dbname=%s;charset=%s',
            $cfg['host']     ?? 'localhost',
            $cfg['port']     ?? 3306,
            $cfg['username'] ?? '',
            $cfg['password'] ?? '',
            $cfg['dbname']   ?? '',
            $cfg['charset']  ?? 'utf8mb4'
        );

        return new PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
