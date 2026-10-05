<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\App;
use think\facade\Db;

/** Fill missing descriptive fields from already linked collection snapshots. */
final class CollectionMetadataRepair
{
    public function __construct(private readonly CollectionPayloadNormalizer $normalizer) {}

    /** Preview by default; application writes are backed up before each locked row is changed. */
    public function run(App $app, bool $apply = false): array
    {
        $backup = null;
        $backupPath = null;
        $scanned = $changed = 0;
        $counts = ['actor' => 0, 'director' => 0, 'keywords' => 0, 'type' => 0, 'pubdate' => 0, 'release_date' => 0];
        try {
            if ($apply) {
                $directory = $app->getRuntimePath() . 'metadata-backups';
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('无法创建备份目录');
                $backupPath = $directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.jsonl';
                $backup = fopen($backupPath, 'xb');
                if ($backup === false || !chmod($backupPath, 0600)) throw new RuntimeException('无法创建私有备份文件');
            }
            $cursor = 0;
            do {
                $ids = Db::table('ffx_media')->whereNull('deleted_at')->where('id', '>', $cursor)->order('id')->limit(100)->column('id');
                foreach ($ids as $id) {
                    $cursor = (int) $id;
                    $scanned++;
                    Db::transaction(function () use ($id, $apply, $backup, &$changed, &$counts): void {
                        $query = Db::table('ffx_media')->where('id', (int) $id)->whereNull('deleted_at');
                        if ($apply) $query->lock(true);
                        $media = $query->field('id,metadata,release_date,updated_at')->find();
                        if ($media === null) return;
                        $patch = [];
                        $refCursor = null;
                        // Read one snapshot at a time; raw playback payloads can be large.
                        do {
                            $refs = Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', (int) $id);
                            if ($refCursor !== null) $refs->where('id', '<', $refCursor);
                            $ref = $refs->field('id,payload')->order('id', 'desc')->find();
                            if ($ref === null) break;
                            $refCursor = (int) $ref['id'];
                            $snapshot = is_array($ref['payload']) ? $ref['payload'] : json_decode((string) $ref['payload'], true);
                            if (is_array($snapshot)) $patch = array_replace($patch, $this->patch(array_replace($media, $patch), [$snapshot]));
                        } while (true);
                        if ($patch === []) return;
                        $before = json_decode((string) ($media['metadata'] ?? '{}'), true) ?: [];
                        $after = json_decode((string) ($patch['metadata'] ?? $media['metadata'] ?? '{}'), true) ?: [];
                        if ($apply) {
                            $line = json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                            if (fwrite($backup, $line) !== strlen($line) || !fflush($backup) || !fsync($backup)) throw new RuntimeException('备份写入失败，已取消当前记录修复');
                            // Preserve publication/update dates and all playback records.
                            Db::table('ffx_media')->where('id', (int) $id)->update($patch + ['updated_at' => $media['updated_at']]);
                        }
                        $changed++;
                        foreach (['actor', 'director', 'keywords', 'type', 'pubdate'] as $key) if (($before[$key] ?? null) !== ($after[$key] ?? null)) $counts[$key]++;
                        if (isset($patch['release_date'])) $counts['release_date']++;
                    });
                }
            } while (count($ids) === 100);
        } finally {
            if (is_resource($backup)) fclose($backup);
            // A partial run also needs invalidation: completed rows are committed separately.
            if ($apply && $changed > 0) $app->make(FrontendCache::class)->invalidateHome();
        }
        return ['mode' => $apply ? 'apply' : 'dry-run', 'scanned' => $scanned, 'changed' => $changed, 'fields' => $counts, 'backup' => $backupPath];
    }

    /** @param array<string,mixed> $media @param array<int,array<string,mixed>> $snapshots
     *  @return array<string,mixed> Only fields that need changing; never playback/status.
     */
    public function patch(array $media, array $snapshots): array
    {
        $metadata = $media['metadata'] ?? [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        if (($metadata['inputer'] ?? '') === 'feifeicms') return [];
        $original = $metadata;
        $releaseDate = $media['release_date'] ?? null;
        $patch = [];
        foreach ($snapshots as $snapshot) {
            $incoming = $this->normalizer->media($snapshot);
            foreach (['actor', 'director', 'keywords', 'type', 'pubdate'] as $field) {
                $current = $metadata[$field] ?? null;
                if ($current !== null && (!is_string($current) || trim($current) !== '')) continue;
                $value = $incoming['metadata'][$field] ?? '';
                if (is_string($value) && trim($value) !== '') $metadata[$field] = $value;
            }
            if (($releaseDate === null || $releaseDate === '') && $incoming['release_date'] !== null) {
                $releaseDate = $patch['release_date'] = $incoming['release_date'];
            }
        }
        if ($metadata !== $original) $patch['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $patch;
    }
}
