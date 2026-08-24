<?php
/**
 * Central configuration. Copy this file, fill in real credentials, and
 * never commit the filled-in version to a public repository.
 */
return [
    'db' => [
        'host'     => 'zj3s02purc.pxxldb.pxxl.pro',
	'port'     => 37313,
	'password' => '3c_N_hxmg8yp_drpRX6jbUfhHHw_KA3F',
        'dbname'   => 'pxxldb_19fc83192171935',
        'charset'  => 'utf8mb4',
    ],

  // BotFather token, e.g. 123456789:AAExampleTokenTextGoesHere
    'bot_token'         => '8825194299:AAFNTm5i6Lcij5APfj8R8F0_xDGhRFlnUpk',

    // Public HTTPS URL to public/webhook.php on your hosting
    'webhook_url'       => 'https://yourdomain.com/public/webhook.php',

    // Optional shared secret checked against Telegram's
    // X-Telegram-Bot-Api-Secret-Token header (see setWebhook.php)
    'webhook_secret'    => 'change-this-random-string',

    // Bootstrap admin — always treated as admin even before any row
    // exists in the `admins` table
    'admin_telegram_id' => 8768136016,
    'log_file'          => __DIR__ . '/../storage/logs/app.log',
    'cache_ttl'         => 3600,

    // YouTube Data API v3 key, from Google Cloud Console
    'google_api_key'    => 'AIzaSyBadLW_M8gjTTu1yVJqNsqctEgmNxVWq3E',
    'youtube_api_key'=>'09a3418e6c92fbb3f92079db5dc4fc294a460ec9',

    // Max video size (bytes) sent by URL before falling back to a
    // local download + multipart upload
    'max_url_upload_bytes' => 20 * 1024 * 1024,

    // How long tool77.com results stay cached (seconds). Kept short
    // deliberately — the resolved CDN links it returns are signed and
    // short-lived (see Tool77Service's docblock). Default: 900 (15 min).
    'tool77_cache_ttl' => 900,
];
