<?php

namespace App\Models;

use App\Core\Database;

/**
 * All access to the youtube_downloads table — used by BotController
 * (creating jobs, the "Check Progress" button) and by
 * bin/poll-single.php (the on-demand background poller).
 */
class YoutubeDownload
{
    public static function create(int $userId, int $chatId, ?string $title, string $progressUrl): int
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO youtube_downloads (user_id, chat_id, title, progress_url, status)
             VALUES (:u, :c, :t, :p, "pending")'
        );
        $stmt->execute(['u' => $userId, 'c' => $chatId, 't' => $title, 'p' => $progressUrl]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM youtube_downloads WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Atomically flips pending -> ready and returns true only for the
     * ONE caller that wins the race. The background poller (spawned
     * the moment the job is created) and a user tapping "Check
     * Progress" can both notice "ready" at nearly the same moment —
     * without this, both would send the audio file, so whoever calls
     * claim() first is the only one allowed to actually deliver it.
     */
    public static function claim(int $id): bool
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE youtube_downloads SET status = 'ready' WHERE id = :id AND status = 'pending'");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare('DELETE FROM youtube_downloads WHERE id = :id')->execute(['id' => $id]);
    }
}
