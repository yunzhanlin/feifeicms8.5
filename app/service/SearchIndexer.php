<?php
declare(strict_types=1);

namespace app\service;

use app\model\Media;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use think\facade\Db;

final class SearchIndexer
{
    private const FIELDS = 'id,category_id,title,original_title,subtitle,summary,content,area,language,release_year,media_type,status,published_at,view_count,rating,weight';
    public function __construct(private readonly MeilisearchClientFactory $searchClient)
    {
    }

    public function sync(int $batchSize = 500): int
    {
        $lock = $this->lockName();
        if (!$this->acquire($lock)) throw new \RuntimeException('搜索索引正在同步，请稍后重试');
        $temporary = '';
        $swapSubmitted = false;
        try {
        $client = $this->searchClient->client();
        $indexName = $this->searchClient->index();
        $temporary = $indexName . '_build_' . bin2hex(random_bytes(8));
        $baseline = Db::table('ffx_search_outbox')->select()->toArray();
        $this->waitForTask($client, $client->createIndex($temporary, ['primaryKey' => 'id']));
        $index = $client->index($temporary);
        $this->waitForTask($client, $index->updateSearchableAttributes(['title', 'original_title', 'subtitle', 'summary', 'content', 'area', 'language']));
        $this->waitForTask($client, $index->updateFilterableAttributes(['status', 'category_id', 'release_year', 'media_type']));
        $this->waitForTask($client, $index->updateSortableAttributes(['published_at', 'view_count', 'rating', 'weight']));
        // Build privately. The live index remains untouched if any batch fails.

        $count = 0;
        Media::field(self::FIELDS)
            ->where('status', 'published')->whereNull('deleted_at')
            ->order('id')
            ->chunk(max(1, min(2000, $batchSize)), function ($rows) use ($client, $index, &$count): void {
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

        if ((int) ($index->stats()['numberOfDocuments'] ?? -1) !== $count) throw new \RuntimeException('临时索引数量校验失败，原索引保持不变');
        try { $client->getIndex($indexName); } catch (ApiException $e) {
            if ($e->errorCode !== 'index_not_found') throw $e;
            $this->waitForTask($client, $client->createIndex($indexName, ['primaryKey' => 'id']));
        }
        $task = $client->swapIndexes([[$indexName, $temporary]]);
        $swapSubmitted = true;
        $this->waitForTask($client, $task);
        // Revisions changed during the rebuild remain pending for replay.
        $this->acknowledge($baseline);
        $this->recordSuccess($count);
        try { $this->waitForTask($client, $client->deleteIndex($temporary)); } catch (\Throwable $e) { trace('旧搜索索引清理失败：' . $e->getMessage(), 'warning'); }
        return $count;
        } catch (\Throwable $e) {
            // A timed-out swap may still execute: preserve both sides then.
            if (!$swapSubmitted && $temporary !== '') {
                try { $client->deleteIndex($temporary); } catch (\Throwable) {}
            }
            throw $e;
        } finally {
            Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Bounded, retryable drain; never wait for another sync worker. */
    public function flushPending(int $batchSize = 100, int $taskTimeout = 1500): int
    {
        if ($this->pendingCount() === 0) return 0;
        $lock = $this->lockName();
        if (!$this->acquire($lock)) return 0;
        try {
            $batch = Db::table('ffx_search_outbox')->order('updated_at')->order('media_id')->limit(max(1, min(2000, $batchSize)))->select()->toArray();
            if ($batch === []) return 0;
            $ids = array_map('intval', array_column($batch, 'media_id'));
            $documents = array_values(Media::field(self::FIELDS)->whereIn('id', $ids)->where('status', 'published')->whereNull('deleted_at')->select()->toArray());
            $remove = array_values(array_diff($ids, array_map('intval', array_column($documents, 'id'))));
            $client = $this->searchClient->client();
            $index = $client->getIndex($this->searchClient->index());
            if ($documents !== []) $this->waitForTask($client, $index->addDocuments($documents, 'id'), $taskTimeout);
            if ($remove !== []) $this->waitForTask($client, $index->deleteDocuments($remove), $taskTimeout);
            $this->acknowledge($batch);
            $this->recordSuccess((int) ($index->stats()['numberOfDocuments'] ?? 0));
            return count($batch);
        } finally {
            Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function pendingCount(): int
    {
        return (int) Db::table('ffx_search_outbox')->count();
    }

    private function lockName(): string
    {
        return 'ff_search_' . substr(hash('sha256', $this->searchClient->host() . '/' . $this->searchClient->index()), 0, 48);
    }

    private function acquire(string $lock): bool
    {
        return (int) (Db::query('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]['acquired'] ?? 0) === 1;
    }

    private function acknowledge(array $batch): void
    {
        Db::transaction(static function () use ($batch): void {
            foreach ($batch as $row) Db::table('ffx_search_outbox')->where('media_id', $row['media_id'])->where('revision', $row['revision'])->delete();
        });
    }

    private function recordSuccess(int $count): void
    {
        $pending = $this->pendingCount();
        Db::execute('INSERT INTO ffx_search_state (index_name,last_success_at,pending_count,indexed_count) VALUES (?,UTC_TIMESTAMP(6),?,?) ON DUPLICATE KEY UPDATE last_success_at=UTC_TIMESTAMP(6),pending_count=?,indexed_count=?', [$this->searchClient->index(), $pending, $count, $pending, $count]);
    }

    /** @param array{taskUid:int} $task */
    private function waitForTask(Client $client, array $task, int $timeout = 30000): void
    {
        $result = $client->waitForTask($task['taskUid'], $timeout);
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new \RuntimeException(sprintf(
                'Meilisearch task %d failed: %s',
                $task['taskUid'],
                json_encode($result['error'] ?? $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        }
    }
}
