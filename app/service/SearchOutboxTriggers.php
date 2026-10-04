<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Repair old table-only backups after restoration without rerunning migration 15. */
final class SearchOutboxTriggers
{
    /** @return array<string, string> */
    public static function statements(): array
    {
        return [
            'ffx_media_search_insert' => <<<'SQL'
CREATE TRIGGER `ffx_media_search_insert` AFTER INSERT ON `ffx_media` FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id) VALUES (NEW.id)
ON DUPLICATE KEY UPDATE revision = revision + 1
SQL,
            'ffx_media_search_update' => <<<'SQL'
CREATE TRIGGER `ffx_media_search_update` AFTER UPDATE ON `ffx_media` FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id)
SELECT NEW.id WHERE NOT (
  OLD.title <=> NEW.title AND OLD.original_title <=> NEW.original_title AND
  OLD.subtitle <=> NEW.subtitle AND OLD.summary <=> NEW.summary AND
  OLD.content <=> NEW.content AND OLD.area <=> NEW.area AND
  OLD.language <=> NEW.language AND OLD.category_id <=> NEW.category_id AND
  OLD.release_year <=> NEW.release_year AND OLD.media_type <=> NEW.media_type AND
  OLD.status <=> NEW.status AND OLD.deleted_at <=> NEW.deleted_at AND
  OLD.published_at <=> NEW.published_at AND OLD.rating <=> NEW.rating AND OLD.weight <=> NEW.weight
) ON DUPLICATE KEY UPDATE revision = revision + 1
SQL,
            'ffx_media_search_delete' => <<<'SQL'
CREATE TRIGGER `ffx_media_search_delete` AFTER DELETE ON `ffx_media` FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id) VALUES (OLD.id)
ON DUPLICATE KEY UPDATE revision = revision + 1
SQL,
        ];
    }

    public static function ensure(): void
    {
        $tables = array_column(Db::query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ffx_media','ffx_search_outbox','ffx_schema_versions')"), 'name');
        if (count($tables) !== 3 || (int) Db::table('ffx_schema_versions')->where('version', '>=', 15)->count() === 0) return;
        $existing = array_column(Db::query("SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='ffx_media'"), 'name');
        foreach (self::statements() as $name => $sql) {
            if (!in_array($name, $existing, true)) Db::execute($sql);
        }
    }
}
