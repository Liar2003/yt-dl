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

    $sqlFile = __DIR__ . '/database/schema.sql';

    if (!file_exists($sqlFile)) {
        exit("database.sql not found.\n");
    }

    $sql = file_get_contents($sqlFile);

    // Execute all SQL statements
    $pdo->exec($sql);

    echo "✅ Database tables created successfully.\n";

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}