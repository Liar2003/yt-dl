-- =====================================================================
-- TikTok & YouTube Downloader Bot — Database Schema (SQLite)
-- Mirror of schema.sql / migrate_v4.sql / migrate_v5.sql / db.sql,
-- translated for SQLite (no ENUM, no ENGINE, no ON DUPLICATE KEY,
-- AUTOINCREMENT instead of AUTO_INCREMENT, TEXT defaults instead of
-- CURRENT_TIMESTAMP ON UPDATE).
-- =====================================================================

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------
-- users: every Telegram user who has talked to the bot
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `telegram_id` INTEGER NOT NULL UNIQUE,
    `username` TEXT NULL,
    `first_name` TEXT NULL,
    `last_name` TEXT NULL,
    `is_banned` INTEGER NOT NULL DEFAULT 0,
    `joined_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS `idx_users_is_banned` ON `users` (`is_banned`);

-- ---------------------------------------------------------------------
-- admins: telegram_ids with admin-panel access (beyond the bootstrap
-- admin_telegram_id set in config/config.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `telegram_id` INTEGER NOT NULL UNIQUE,
    `added_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- downloads: a log of every completed download, used for /history and
-- daily statistics
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `downloads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `url` TEXT NOT NULL,
    `type` TEXT NOT NULL CHECK (`type` IN
        ('video','image','youtube_audio','youtube_video',
         'facebook_video','facebook_audio','tiktok_audio','youtube_link')),
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS `idx_downloads_user_id`  ON `downloads` (`user_id`);
CREATE INDEX IF NOT EXISTS `idx_downloads_created`  ON `downloads` (`created_at`);

-- ---------------------------------------------------------------------
-- required_channels: channels a user must join before downloading
-- (force-join feature)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `required_channels` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `channel_username` TEXT NOT NULL,
    `channel_title` TEXT NULL,
    `added_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- settings: simple key/value store for feature toggles
-- (force_join_enabled, maintenance_mode, ...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `setting_key` TEXT NOT NULL UNIQUE,
    `setting_value` TEXT NULL
);

-- ---------------------------------------------------------------------
-- statistics: one row per calendar day, incremented as events happen
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `statistics` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `stat_date` TEXT NOT NULL UNIQUE,
    `downloads_count` INTEGER NOT NULL DEFAULT 0,
    `new_users_count` INTEGER NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------
-- logs: application log (errors, API failures, admin actions)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `level` TEXT NOT NULL DEFAULT 'info'
        CHECK (`level` IN ('info','warning','error')),
    `message` TEXT NOT NULL,
    `context` TEXT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS `idx_logs_level`    ON `logs` (`level`);
CREATE INDEX IF NOT EXISTS `idx_logs_created`  ON `logs` (`created_at`);

-- ---------------------------------------------------------------------
-- cache: generic TTL cache — TikWM / Tool77 / TikTok audio responses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cache` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `cache_key` TEXT NOT NULL UNIQUE,
    `cache_value` TEXT NOT NULL,
    `expires_at` TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS `idx_cache_expires` ON `cache` (`expires_at`);

-- ---------------------------------------------------------------------
-- pending_requests: one unresolved request per user, held while they
-- complete the force-join flow, then resumed
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_requests` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL UNIQUE,
    `url` TEXT NOT NULL,
    `chat_id` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- youtube_downloads: YouTube conversions that did not finish within the
-- 25s inline wait, polled later via the "Check Progress" button
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `youtube_downloads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `chat_id` INTEGER NOT NULL,
    `title` TEXT NULL,
    `progress_url` TEXT NOT NULL,
    `status` TEXT NOT NULL DEFAULT 'pending'
        CHECK (`status` IN ('pending','ready')),
    `download_url` TEXT NULL,
    `thumbnail_url` TEXT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- ads: stored forwarded messages an admin adds
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `source_chat_id` INTEGER NOT NULL,
    `source_message_id` INTEGER NOT NULL,
    `added_by` INTEGER NOT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- pending_broadcasts: one row per admin currently "armed" for /forward
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_broadcasts` (
    `admin_id` INTEGER PRIMARY KEY,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------
-- seed defaults so the bot behaves sanely on first boot
-- ---------------------------------------------------------------------
INSERT OR IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('force_join_enabled', '0'),
    ('maintenance_mode', '0'),
    ('ads_enabled', '0');
