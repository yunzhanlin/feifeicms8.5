<?php
declare(strict_types=1);
namespace app\service;
use think\facade\Db;

final class CommentReplies
{
    /** One bounded query; MySQL 5.7 compatible, no window functions required. */
    public static function recent(array $parentIds, int $limit = 20, string $targetType = 'site'): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $parentIds), static fn (int $id): bool => $id > 0))), 0, 100);
        if ($ids === []) return [];
        if (!in_array($targetType, ['site', 'media'], true)) throw new \InvalidArgumentException('评论类型无效');
        $limit = max(1, min(50, $limit));
        $parts = [];
        foreach ($ids as $index => $id) $parts[] = "SELECT * FROM (SELECT id,parent_id,content,created_at,author_name FROM ffx_comments WHERE parent_id=? AND target_type='" . $targetType . "' AND status='approved' AND deleted_at IS NULL ORDER BY id DESC LIMIT " . $limit . ') AS replies_' . $index;
        $grouped = [];
        foreach (Db::query(implode(' UNION ALL ', $parts), $ids) as $row) $grouped[(int) $row['parent_id']][] = $row;
        foreach ($grouped as &$rows) $rows = array_reverse($rows);
        return $grouped;
    }
}
