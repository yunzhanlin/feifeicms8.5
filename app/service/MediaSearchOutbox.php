<?php
declare(strict_types=1);

namespace app\service;

use PDO;

/** Application-written search journal; always use the media write's PDO/transaction. */
final class MediaSearchOutbox
{
    public const FIELDS = ['title', 'original_title', 'subtitle', 'summary', 'content', 'area', 'language',
        'category_id', 'release_year', 'media_type', 'status', 'deleted_at', 'published_at', 'rating', 'weight'];

    public static function hasTriggers(PDO $pdo): bool
    {
        $query = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='ffx_media'");
        $names = $query->fetchAll(PDO::FETCH_COLUMN);
        $query->closeCursor();
        return count(array_intersect(array_keys(SearchOutboxTriggers::statements()), $names)) === 3;
    }

    public static function enqueue(PDO $pdo, int $id): void
    {
        if (!$pdo->inTransaction()) throw new \LogicException('搜索变更必须与影片写入处于同一事务');
        $query = $pdo->prepare('INSERT INTO ffx_search_outbox (media_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1');
        $query->execute([$id]);
        $query->closeCursor();
    }

    /** Queue the original match before REPLACE changes it; no unbounded PHP ID array. */
    public static function enqueueReplacement(PDO $pdo, string $where, array $params): void
    {
        if (!$pdo->inTransaction()) throw new \LogicException('搜索变更必须与影片写入处于同一事务');
        if (self::hasTriggers($pdo)) return;
        $query = $pdo->prepare('INSERT INTO ffx_search_outbox (media_id) SELECT id FROM ffx_media WHERE ' . $where
            . ' ORDER BY id ON DUPLICATE KEY UPDATE revision=revision+1');
        $query->execute($params);
        $query->closeCursor();
    }
}
