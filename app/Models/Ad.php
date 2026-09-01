<?php

namespace App\Models;

use App\Core\Database;

/**
 * Promo messages an admin adds by forwarding them to the bot. Each row
 * just points at the original forwarded message (source_chat_id +
 * source_message_id, living in the admin's chat with the bot) — ads
 * are shown to end users via Telegram's copyMessage, re-reading from
 * that original message each time rather than storing its content.
 * If an admin deletes it from their chat afterward, showing that ad
 * will start silently failing (copyMessage errors get logged, not
 * shown to the end user — see AdsService::maybeShow()).
 */
class Ad
{
    public static function create(int $sourceChatId, int $sourceMessageId, int $addedBy): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO ads (source_chat_id, source_message_id, added_by) VALUES (:c, :m, :a)'
        );
        $stmt->execute(['c' => $sourceChatId, 'm' => $sourceMessageId, 'a' => $addedBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function random(): ?array
    {
        $pdo = Database::getInstance();
        $row = $pdo->query('SELECT * FROM ads ORDER BY RANDOM() LIMIT 1')->fetch();
        return $row ?: null;
    }

    /** @return array<array{id:int,source_chat_id:int,source_message_id:int,added_by:int,created_at:string}> */
    public static function all(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query('SELECT * FROM ads ORDER BY id DESC')->fetchAll();
    }

    public static function delete(int $id): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('DELETE FROM ads WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function count(): int
    {
        $pdo = Database::getInstance();
        return (int) $pdo->query('SELECT COUNT(*) FROM ads')->fetchColumn();
    }
}
