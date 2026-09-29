<?php
declare(strict_types=1);

namespace app\service\search;

final readonly class SearchResult
{
    /** @param array<int, array<string, mixed>> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public string $driver,
    ) {
    }
}
