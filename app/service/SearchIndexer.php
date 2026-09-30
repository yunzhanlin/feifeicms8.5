<?php
declare(strict_types=1);

namespace app\service;

use app\model\Media;
use Meilisearch\Client;

final class SearchIndexer
{
    public function __construct(private readonly SiteSettings $siteSettings)
    {
    }

    public function sync(int $batchSize = 500): int
    {
        $defaults = (array) config('feifei.search.meilisearch');
        $host = $this->siteSettings->string('admin.cache.search_host', (string) ($defaults['host'] ?? 'http://127.0.0.1:7700'));
        $key = $this->siteSettings->string('admin.cache.search_key', (string) ($defaults['key'] ?? ''));
        $indexName = $this->siteSettings->string('admin.cache.search_index', (string) ($defaults['index'] ?? 'vod'));
        $client = new Client($host, $key);
        $index = $client->index($indexName);
        $this->waitForTask($client, $index->updateSearchableAttributes(['title', 'original_title', 'subtitle', 'summary', 'content', 'area', 'language']));
        $this->waitForTask($client, $index->updateFilterableAttributes(['status', 'category_id', 'release_year', 'media_type']));
        $this->waitForTask($client, $index->updateSortableAttributes(['published_at', 'view_count', 'rating', 'weight']));
        // This command is a full reconciliation, not an append-only import.
        // Removing the previous documents first prevents deleted or unpublished
        // removed or unpublished rows from surviving indefinitely in the search index.
        $this->waitForTask($client, $index->deleteAllDocuments());

        $count = 0;
        Media::field('id,category_id,title,original_title,subtitle,summary,content,area,language,release_year,media_type,status,published_at,view_count,rating,weight')
            ->where('status', 'published')->whereNull('deleted_at')
            ->order('id')
            ->chunk($batchSize, function ($rows) use ($client, $index, &$count): void {
                // Model collections preserve the database chunk's original numeric
                // offsets. The second and later chunks would therefore JSON-encode
                // as an object instead of a document list, which Meilisearch rejects
                // as "missing_document_id". Always submit a dense JSON array.
                $documents = array_values($rows->toArray());
                if ($documents !== []) {
                    $this->waitForTask($client, $index->addDocuments($documents, 'id'));
                    $count += count($documents);
                }
            }, 'id');

        return $count;
    }

    /** @param array{taskUid:int} $task */
    private function waitForTask(Client $client, array $task): void
    {
        $result = $client->waitForTask($task['taskUid'], 30_000);
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new \RuntimeException(sprintf(
                'Meilisearch task %d failed: %s',
                $task['taskUid'],
                json_encode($result['error'] ?? $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        }
    }
}
