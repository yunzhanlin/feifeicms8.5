<?php
declare(strict_types=1);

namespace app\service\search;

use app\model\Media;
use app\service\MeilisearchClientFactory;

final class MeilisearchVodSearch implements VodSearchDriver
{
    public function __construct(private readonly MeilisearchClientFactory $searchClient, private readonly \app\service\SearchIndexer $indexer)
    {
    }

    public function search(string $keyword, int $page, int $pageSize): SearchResult
    {
        $this->indexer->flushPending();
        if ($this->indexer->pendingCount() > 0) throw new \RuntimeException('搜索增量仍在同步，请使用数据库搜索');
        $client = $this->searchClient->client();
        $indexName = $this->searchClient->index();
        $raw = $client->index($indexName)->search($keyword, [
            'offset' => ($page - 1) * $pageSize,
            'limit' => $pageSize,
            'filter' => 'status = "published"',
            'attributesToRetrieve' => ['id'],
        ])->getRaw();

        $ids = array_values(array_filter(array_map(
            static fn (array $hit): int => (int) ($hit['id'] ?? 0),
            (array) ($raw['hits'] ?? [])
        )));

        if ($ids === []) {
            return new SearchResult([], (int) ($raw['estimatedTotalHits'] ?? 0), $page, $pageSize, 'meilisearch');
        }

        $rows = Media::whereIn('id', $ids)->where('status', 'published')->whereNull('deleted_at')->select()->toArray();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $items = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }

        return new SearchResult($items, (int) ($raw['estimatedTotalHits'] ?? count($items)), $page, $pageSize, 'meilisearch');
    }
}
