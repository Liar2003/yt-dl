-- TikTok Downloader Bot – Full Database Schema
-- Users table
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `telegram_id` BIGINT NOT NULL UNIQUE,
    `username` VARCHAR(255) NULL,
    `first_name` VARCHAR(255) NULL,
    `status` ENUM('active','banned') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Admins table
CREATE TABLE `admins` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `telegram_id` BIGINT NOT NULL UNIQUE,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Downloads table
CREATE TABLE `downloads` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `tiktok_id` VARCHAR(255) NOT NULL,
    `type` ENUM('video','image') NOT NULL,
    `video_url` TEXT NULL,
    `audio_url` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Required channels (force join)
CREATE TABLE `required_channels` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` VARCHAR(255) NOT NULL,
    `channel_username` VARCHAR(255) NOT NULL,
    `channel_link` VARCHAR(255) NOT NULL,
    `status` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Settings (single row)
CREATE TABLE `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `force_join` TINYINT(1) NOT NULL DEFAULT 0,
    `maintenance` TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Statistics (daily)
CREATE TABLE `statistics` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `date` DATE NOT NULL UNIQUE,
    `users` INT UNSIGNED NOT NULL DEFAULT 0,
    `videos` INT UNSIGNED NOT NULL DEFAULT 0,
    `images` INT UNSIGNED NOT NULL DEFAULT 0,
    `audios` INT UNSIGNED NOT NULL DEFAULT 0,
    `errors` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Logs
CREATE TABLE `logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT NULL,
    `action` VARCHAR(255) NOT NULL,
    `message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- API response cache
CREATE TABLE `cache` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cache_key` VARCHAR(255) NOT NULL UNIQUE,
    `response` JSON NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Insert default settings row
INSERT INTO `settings` (`force_join`, `maintenance`) VALUES (0, 0);