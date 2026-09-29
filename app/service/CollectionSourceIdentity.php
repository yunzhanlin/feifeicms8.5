<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Builds the FeiFeiCMS-compatible vod_reurl value from normalized collection
 * references. The external-reference table remains authoritative; this value
 * is the editable legacy representation shown in the video form.
 */
final class CollectionSourceIdentity
{
    /** @var array<int, string> */
    private array $sourceNames = [];

    public function marker(string $sourceName, int $sourceId, string $externalId, string $upstreamReference = ''): string
    {
        $upstreamReference = trim($upstreamReference);
        if ($upstreamReference !== '') return mb_substr($upstreamReference, 0, 500);

        $key = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $sourceName), '-'));
        if ($key === '') $key = 'source-' . $sourceId;
        return mb_substr('[' . $key . ']=[' . trim($externalId) . ']', 0, 500);
    }

    public function markerForSource(int $sourceId, string $externalId, string $upstreamReference = ''): string
    {
        if (!array_key_exists($sourceId, $this->sourceNames)) {
            $this->sourceNames[$sourceId] = (string) Db::table('ffx_collection_sources')->where('id', $sourceId)->value('name');
        }
        return $this->marker($this->sourceNames[$sourceId], $sourceId, $externalId, $upstreamReference);
    }

    public function forMedia(int $mediaId, string $stored = ''): string
    {
        $markers = $this->split($stored);
        $rows = Db::table('ffx_external_refs')->alias('r')
            ->leftJoin(['ffx_collection_sources' => 's'], 's.id=r.source_id')
            ->where('r.entity_type', 'media')->where('r.entity_id', $mediaId)
            ->field('r.source_id,r.external_id,r.source_url,s.name')->order('r.id')->select()->toArray();
        foreach ($rows as $row) {
            $markers[] = $this->marker(
                (string) ($row['name'] ?? ''), (int) ($row['source_id'] ?? 0),
                (string) ($row['external_id'] ?? ''), (string) ($row['source_url'] ?? '')
            );
        }
        return $this->join($markers);
    }

    public function merge(string ...$values): string
    {
        $markers = [];
        foreach ($values as $value) $markers = array_merge($markers, $this->split($value));
        return $this->join($markers);
    }

    /** @return array<int, string> */
    private function split(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [];
        preg_match_all('/\[[^\]]+\]=\[[^\]]+\]/u', $value, $matches);
        if (($matches[0] ?? []) !== []) return array_values($matches[0]);
        return [$value];
    }

    /** @param array<int, string> $markers */
    private function join(array $markers): string
    {
        $result = '';
        foreach (array_values(array_unique(array_filter(array_map('trim', $markers)))) as $marker) {
            $candidate = $result === '' ? $marker : $result . ',' . $marker;
            if (mb_strlen($candidate) > 500) break;
            $result = $candidate;
        }
        return $result;
    }
}
