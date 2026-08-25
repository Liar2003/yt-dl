-- =====================================================================
-- TikTok & YouTube Downloader Bot — Database Schema
-- Engine: InnoDB, Charset: utf8mb4
-- =====================================================================
-- ---------------------------------------------------------------------
-- users: every Telegram user who has talked to the bot
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `telegram_id` BIGINT NOT NULL UNIQUE,
    `username` VARCHAR(255) NULL,
    `first_name` VARCHAR(255) NULL,
    `last_name` VARCHAR(255) NULL,
    `is_banned` TINYINT(1) NOT NULL DEFAULT 0,
    `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`is_banned`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- admins: telegram_ids with admin-panel access (beyond the bootstrap
-- admin_telegram_id set in config/config.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `telegram_id` BIGINT NOT NULL UNIQUE,
    `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- downloads: a log of every completed download, used for /history and
-- daily statistics
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `downloads` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NOT NULL,
    `url` TEXT NOT NULL,
    `type` ENUM('video','image','youtube_audio','youtube_video','facebook_video','facebook_audio','tiktok_audio','youtube_link') NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`user_id`),
    INDEX (`created_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- required_channels: channels a user must join before downloading
-- (force-join feature)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `required_channels` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_username` VARCHAR(255) NOT NULL,
    `channel_title` VARCHAR(255) NULL,
    `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- settings: simple key/value store for feature toggles
-- (force_join_enabled, maintenance_mode, ...)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- statistics: one row per calendar day, incremented as events happen
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `statistics` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `stat_date` DATE NOT NULL UNIQUE,
    `downloads_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `new_users_count` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- logs: application log (errors, API failures, admin actions)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level` ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    `message` TEXT NOT NULL,
    `context` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`level`),
    INDEX (`created_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- cache: generic TTL cache — TikWM API responses and extracted TikTok
-- audio URLs (keyed audio_{tiktokId}) are both stored here
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cache` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cache_key` VARCHAR(191) NOT NULL UNIQUE,
    `cache_value` LONGTEXT NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    INDEX (`expires_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- pending_requests: one unresolved request per user, held while they
-- complete the force-join flow, then resumed
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pending_requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NOT NULL UNIQUE,
    `url` TEXT NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- youtube_downloads: YouTube conversions that did not finish within the
-- 25s inline wait, polled later via the "Check Progress" button
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `youtube_downloads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `title` VARCHAR(255) NULL,
    `progress_url` VARCHAR(500) NOT NULL,
    `status` ENUM('pending','ready') NOT NULL DEFAULT 'pending',
    `download_url` TEXT NULL,
    `thumbnail_url` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- seed defaults so the bot behaves sanely on first boot
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('force_join_enabled', '0'),
    ('maintenance_mode', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
