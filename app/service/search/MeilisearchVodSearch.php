<?php
declare(strict_types=1);

namespace app\service\search;

use app\model\Media;
use app\service\SiteSettings;
use Meilisearch\Client;

final class MeilisearchVodSearch implements VodSearchDriver
{
    public function __construct(private readonly SiteSettings $settings)
    {
    }

    public function search(string $keyword, int $page, int $pageSize): SearchResult
    {
        $defaults = (array) config('feifei.search.meilisearch');
        $host = $this->settings->string('admin.cache.search_host', (string) ($defaults['host'] ?? 'http://127.0.0.1:7700'));
        $key = $this->settings->string('admin.cache.search_key', (string) ($defaults['key'] ?? ''));
        $indexName = $this->settings->string('admin.cache.search_index', (string) ($defaults['index'] ?? 'vod'));
        $client = new Client($host, $key);
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
