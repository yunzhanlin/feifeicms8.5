<?php
declare(strict_types=1);

namespace app\service;

final class CollectionCategoryMap
{
    /**
     * Keep zero as an explicit "unbound" choice. Missing keys may still receive
     * automatic suggestions, while saved zero values must remain unbound.
     *
     * @param array<array-key, mixed> $posted
     * @param list<int> $validLocalIds
     * @return array<string, int>
     */
    public function normalize(array $posted, array $validLocalIds): array
    {
        $mapping = [];
        foreach ($posted as $upstreamId => $localId) {
            $upstreamId = mb_substr(trim((string) $upstreamId), 0, 80);
            if ($upstreamId === '') continue;
            $localId = (int) $localId;
            if ($localId === 0 || in_array($localId, $validLocalIds, true)) {
                $mapping[$upstreamId] = $localId;
            }
        }
        return $mapping;
    }

    /** @param array<string, mixed> $mapping */
    public function resolve(array $mapping, string $upstreamId, int $defaultCategory): int
    {
        if (array_key_exists($upstreamId, $mapping)) return max(0, (int) $mapping[$upstreamId]);
        return $defaultCategory;
    }
}
