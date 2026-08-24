-- Run this ONLY if you already deployed an earlier version of
-- schema.sql. A fresh install can just use schema.sql as-is — it
-- already reflects everything below. Safe to run more than once,
-- and safe to run regardless of which earlier version you're on
-- (each statement is a no-op if already applied).

-- From when TikTok/Facebook/YouTube were unified onto tool77.com:
ALTER TABLE `downloads` MODIFY COLUMN `type`
    ENUM('video','image','youtube_audio','youtube_video','facebook_video','facebook_audio','tiktok_audio') NOT NULL;
DROP TABLE IF EXISTS `youtube_downloads`;

-- From when the ads feature was added:
CREATE TABLE IF NOT EXISTS `ads` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `source_chat_id` BIGINT NOT NULL,
    `source_message_id` BIGINT NOT NULL,
    `added_by` BIGINT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('ads_enabled', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- From when /forward (broadcast a forwarded message) was added:
CREATE TABLE IF NOT EXISTS `pending_broadcasts` (
    `admin_id` BIGINT UNSIGNED PRIMARY KEY,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
