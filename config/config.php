<?php
/**
 * Central configuration. Copy this file, fill in real credentials, and
 * never commit the filled-in version to a public repository.
 */
return [
    'db' => [
        'host'     => 'localhost',
        'dbname'   => 'tiktok_bot',
        'username' => 'your_db_username',
        'password' => 'your_db_password',
        'charset'  => 'utf8mb4',
    ],

    // BotFather token, e.g. 123456789:AAExampleTokenTextGoesHere
    'bot_token'         => 'YOUR_BOT_TOKEN',

    // Public HTTPS URL to public/webhook.php on your hosting
    'webhook_url'       => 'https://yourdomain.com/public/webhook.php',

    // Bootstrap admin — always treated as admin even before any row
    // exists in the `admins` table
    'admin_telegram_id' => 123456789,

    'log_file'          => __DIR__ . '/../storage/logs/app.log',
    'cache_ttl'         => 3600,

    // YouTube Data API v3 key, from Google Cloud Console
    'google_api_key'    => 'YOUR_YOUTUBE_DATA_API_KEY',

    // Max video size (bytes) sent by URL before falling back to a
    // local download + multipart upload
    'max_url_upload_bytes' => 20 * 1024 * 1024,

    // Path to the PHP CLI binary, used to spawn the background
    // YouTube-conversion poller (see BotController::spawnBackgroundPoller
    // and bin/poll-single.php). 'php' works if it's on PATH; on some
    // shared hosts you'll need a full/versioned path instead, e.g.
    // '/usr/bin/php8.2' — check with `which php` over SSH, or ask your
    // host. Only matters if exec() is enabled; see the README.
    'php_cli_path' => 'php',
];
