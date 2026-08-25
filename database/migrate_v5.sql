-- Run this ONLY if you already deployed an earlier version of
-- schema.sql. A fresh install can just use schema.sql as-is — it
-- already reflects everything below. Safe to run more than once.

-- From when YouTube switched from direct media delivery to a menu of
-- inline download links (logged as 'youtube_link'):
ALTER TABLE `downloads` MODIFY COLUMN `type`
    ENUM('video','image','youtube_audio','youtube_video','facebook_video','facebook_audio','tiktok_audio','youtube_link') NOT NULL;
