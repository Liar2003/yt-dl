<?php

$config = require __DIR__ . '/config/config.php';

$db = $config['db'];

$dsn = sprintf(
    "mysql:host=%s;port=%d;dbname=%s;charset=%s",
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset']
);

try {
    $pdo = new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // schema.sql creates everything in final form; migrate_v5.sql is a
    // no-op afterwards but kept so CLI and the bot's /setup command
    // always produce identical results.
    foreach (['schema.sql', 'migrate_v5.sql'] as $file) {
        $sqlFile = __DIR__ . '/database/' . $file;

        if (!file_exists($sqlFile)) {
            echo "⚠️ {$file} not found — skipped.\n";
            continue;
        }

        $pdo->exec(file_get_contents($sqlFile));
        echo "✅ {$file} applied.\n";
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}