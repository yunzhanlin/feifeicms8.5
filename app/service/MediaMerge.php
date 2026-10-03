<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * FeiFeiCMS-compatible manual video merge.
 *
 * The one published record is the primary video. Draft records are folded into
 * it, retaining collection identities, all playback lines and unique scenario
 * set. Source records are archived instead of being physically deleted so the
 * merge remains auditable and recoverable.
 */
final class MediaMerge
{
    public function __construct(private readonly CollectionSourceIdentity $sourceIdentity)
    {
    }

    /** @param array<int, int|string> $ids @return array{primary_id:int,merged_ids:array<int,int>,play_sources:int,scenarios:int} */
    public function merge(array $ids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0))), 0, 200);
        if (count($ids) < 2) throw new \RuntimeException('请至少选择两部需要合并的影片');

        return Db::transaction(function () use ($ids): array {
        $rows = Db::table('ffx_media')->whereIn('id', $ids)->whereNull('deleted_at')->order('id')->lock(true)->select()->toArray();
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        $ordered = array_values(array_filter(array_map(static fn (int $id): ?array => $byId[$id] ?? null, $ids)));
        if (count($ordered) !== count($ids)) throw new \RuntimeException('选择的影片中包含不存在或已归档的记录');

        $published = array_values(array_filter($ordered, static fn (array $row): bool => (string) $row['status'] === 'published'));
        if (count($published) !== 1) throw new \RuntimeException('合并时必须且只能保留一部“已审核”影片作为主影片，其余影片请先取消审核');
        $primary = $published[0];
        $children = array_values(array_filter($ordered, static fn (array $row): bool => (int) $row['id'] !== (int) $primary['id']));
        $primaryId = (int) $primary['id'];
        $childIds = array_map(static fn (array $row): int => (int) $row['id'], $children);

            $this->mergePrimaryFields($primary, $children);
            $playSources = $this->mergePlayback($primaryId, $childIds);
            $scenarios = $this->mergeScenarios($primaryId, $childIds);
            $this->mergeRelations($primaryId, $childIds);
            $now = gmdate('Y-m-d H:i:s');
            Db::table('ffx_media')->whereIn('id', $childIds)->update(['status' => 'archived', 'deleted_at' => $now, 'updated_at' => $now]);
            return ['primary_id' => $primaryId, 'merged_ids' => $childIds, 'play_sources' => $playSources, 'scenarios' => $scenarios];
        });
    }

    /** @param array<string,mixed> $primary @param array<int,array<string,mixed>> $children */
    private function mergePrimaryFields(array $primary, array $children): void
    {
        $textFields = ['original_title', 'subtitle', 'douban_id', 'imdb_id', 'summary', 'content', 'poster_url', 'backdrop_url', 'area', 'language', 'release_date', 'episode_label'];
        $maxFields = ['release_year', 'episode_total', 'rating', 'rating_count', 'weight'];
        $sumFields = ['view_count', 'like_count', 'dislike_count'];
        $update = [];
        $meta = $this->jsonObject($primary['metadata'] ?? null);
        $sourceRefs = [(string) ($meta['source_ref'] ?? '')];
        foreach ($children as $child) {
            foreach ($textFields as $field) {
                if ($this->emptyValue($primary[$field] ?? null) && !$this->emptyValue($child[$field] ?? null)) {
                    $primary[$field] = $update[$field] = $child[$field];
                }
            }
            foreach ($maxFields as $field) {
                $value = max((float) ($primary[$field] ?? 0), (float) ($child[$field] ?? 0));
                if ($value > 0) $primary[$field] = $update[$field] = in_array($field, ['rating'], true) ? $value : (int) $value;
            }
            foreach ($sumFields as $field) {
                $primary[$field] = $update[$field] = (int) ($primary[$field] ?? 0) + (int) ($child[$field] ?? 0);
            }
            $childMeta = $this->jsonObject($child['metadata'] ?? null);
            $sourceRefs[] = (string) ($childMeta['source_ref'] ?? '');
            foreach ($childMeta as $key => $value) {
                if (!array_key_exists($key, $meta) || $this->emptyValue($meta[$key])) $meta[$key] = $value;
            }
        }
        $meta['source_ref'] = $this->sourceIdentity->merge(...$sourceRefs);
        $update['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $update['is_completed'] = (int) max((int) ($primary['is_completed'] ?? 0), ...array_map(static fn (array $row): int => (int) ($row['is_completed'] ?? 0), $children));
        $update['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_media')->where('id', (int) $primary['id'])->update($update);
    }

    /** @param array<int,int> $childIds */
    private function mergePlayback(int $primaryId, array $childIds): int
    {
        // Reserve all primary keys BEFORE considering children, regardless of
        // each source's sort order. Keep conflicting lines instead of replacing
        // episodes (which also destroys history/subtitle references through FKs).
        $primarySources = Db::table('ffx_play_sources')->where('media_id', $primaryId)->order('sort_order')->order('id')->select()->toArray();
        $sources = array_merge($primarySources, Db::table('ffx_play_sources')->whereIn('media_id', $childIds)->order('sort_order')->order('id')->select()->toArray());
        $main = [];
        $sort = 0;
        foreach ($sources as $source) {
            $sourceId = (int) $source['id'];
            $key = (string) $source['source_key'];
            if ((int) $source['media_id'] === $primaryId) {
                $main[$key] = $sourceId;
                $sort = max($sort, (int) $source['sort_order'] + 1);
                continue;
            }
            if (isset($main[$key])) {
                $base = mb_substr($key, 0, 40) . '_merge_' . $sourceId;
                $key = $base;
                $suffix = 0;
                while (isset($main[$key])) $key = $base . '_' . ++$suffix;
            }
            Db::table('ffx_play_sources')->where('id', $sourceId)->update(['media_id' => $primaryId, 'source_key' => $key, 'sort_order' => $sort++]);
            Db::table('ffx_episodes')->where('source_id', $sourceId)->update(['media_id' => $primaryId, 'season_id' => null]);
            $main[$key] = $sourceId;
        }
        return count($main);
    }

    /** @param array<int,int> $childIds */
    private function mergeScenarios(int $primaryId, array $childIds): int
    {
        $main = [];
        foreach (Db::table('ffx_scenarios')->where('media_id', $primaryId)->select()->toArray() as $row) $main[(int) $row['episode_no']] = $row;
        foreach (Db::table('ffx_scenarios')->whereIn('media_id', $childIds)->whereNull('deleted_at')->order('media_id')->order('episode_no')->select()->toArray() as $row) {
            $no = (int) $row['episode_no'];
            if (!isset($main[$no])) {
                Db::table('ffx_scenarios')->where('id', $row['id'])->update(['media_id' => $primaryId]);
                $main[$no] = $row;
            } elseif (trim((string) $main[$no]['content']) === '' && empty($main[$no]['deleted_at'])) {
                Db::table('ffx_scenarios')->where('id', $main[$no]['id'])->update(['content' => $row['content']]);
                $main[$no]['content'] = $row['content'];
            }
            // Conflicting text stays on the archived child for audit/recovery.
        }
        return (int) Db::table('ffx_scenarios')->where('media_id', $primaryId)->whereNull('deleted_at')->count();
    }

    /** @param array<int,int> $childIds */
    private function mergeRelations(int $primaryId, array $childIds): void
    {
        foreach ($childIds as $childId) {
            foreach ([
                ['ffx_media_categories', ['category_id', 'is_primary', 'sort_order']],
                ['ffx_media_tags', ['tag_id']],
                ['ffx_media_people', ['person_id', 'credit_type', 'character_name', 'sort_order']],
            ] as [$table, $fields]) {
                foreach (Db::table($table)->where('media_id', $childId)->select()->toArray() as $row) {
                    $data = ['media_id' => $primaryId];
                    foreach ($fields as $field) $data[$field] = $row[$field];
                    if ($table === 'ffx_media_categories') $data['is_primary'] = 0;
                    try { Db::table($table)->insert($data); } catch (\Throwable) { /* identical relation already exists */ }
                }
            }
            foreach (Db::table('ffx_topic_media')->where('media_id', $childId)->select()->toArray() as $row) {
                try { Db::table('ffx_topic_media')->insert(['topic_id' => $row['topic_id'], 'media_id' => $primaryId, 'sort_order' => $row['sort_order']]); } catch (\Throwable) { }
            }
            Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', $childId)->update(['entity_id' => $primaryId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            Db::table('ffx_comments')->where('target_type', 'media')->where('target_id', $childId)->update(['target_id' => $primaryId]);
            Db::table('ffx_media_assets')->where('media_id', $childId)->update(['media_id' => $primaryId]);
            $this->mergeUserRelation('ffx_favorites', $primaryId, $childId, ['user_id', 'created_at']);
            $this->mergeUserRelation('ffx_media_entitlements', $primaryId, $childId, ['user_id', 'points_spent', 'created_at']);
            $this->mergeUserRelation('ffx_watch_history', $primaryId, $childId, ['user_id', 'episode_id', 'progress_seconds', 'watched_at']);
            foreach (Db::table('ffx_ratings')->where('target_type', 'media')->where('target_id', $childId)->select()->toArray() as $rating) {
                $exists = Db::table('ffx_ratings')->where('target_type', 'media')->where('target_id', $primaryId)->where('user_id', $rating['user_id'])->find();
                if ($exists === null) Db::table('ffx_ratings')->where('id', (int) $rating['id'])->update(['target_id' => $primaryId]);
                else Db::table('ffx_ratings')->where('id', (int) $rating['id'])->delete();
            }
        }
    }

    /** @param array<int,string> $fields */
    private function mergeUserRelation(string $table, int $primaryId, int $childId, array $fields): void
    {
        foreach (Db::table($table)->where('media_id', $childId)->select()->toArray() as $row) {
            $exists = Db::table($table)->where('media_id', $primaryId)->where('user_id', $row['user_id'])->find();
            if ($exists === null) {
                $data = ['media_id' => $primaryId];
                foreach ($fields as $field) $data[$field] = $row[$field];
                Db::table($table)->insert($data);
            }
        }
        Db::table($table)->where('media_id', $childId)->delete();
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === [];
    }
}
