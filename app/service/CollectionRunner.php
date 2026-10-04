<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

final class CollectionRunner
{
    public function __construct(private readonly CollectionHttpClient $http, private readonly EpisodeParser $episodeParser, private readonly CollectionCategoryMap $categoryMap, private readonly CollectionPayloadNormalizer $normalizer, private readonly CollectionSourceIdentity $sourceIdentity, private readonly SiteSettings $settings, private readonly FrontendCache $frontendCache)
    {
    }

    /** @return array<string, int|string> */
    public function run(int $jobId): array
    {
        $this->guardEnabled();
        $job = Db::table('ffx_collection_jobs')->where('id', $jobId)->find();
        if ($job === null) {
            throw new \RuntimeException('采集任务不存在');
        }
        $claimed = Db::table('ffx_collection_jobs')->where('id', $jobId)->where('state', 'queued')->update([
            'state' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'), 'finished_at' => null, 'error_message' => null,
        ]);
        if ($claimed !== 1) {
            throw new \RuntimeException('采集任务已被其他工作进程领取或当前不可执行');
        }
        $source = Db::table('ffx_collection_sources')->where('id', (int) $job['source_id'])->find();
        if ($source === null || $source['status'] !== 'enabled') {
            Db::table('ffx_collection_jobs')->where('id', $jobId)->where('state', 'running')->update([
                'state' => 'failed', 'error_count' => (int) $job['error_count'] + 1,
                'error_message' => '采集源不存在或未启用', 'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
            throw new \RuntimeException('采集源不存在或未启用');
        }

        $processed = $created = $updated = $errors = 0;
        $errorMessages = [];
        try {
            $payload = json_decode((string) ($job['cursor_value'] ?: '{}'), true);
            $payload = is_array($payload) ? $payload : [];
            $page = max(1, (int) ($payload['page'] ?? 1));
            $limit = min(100, max(1, (int) ($payload['limit'] ?? $this->settings->int('admin.collection.batch_size', 50, 1, 100))));
            $pageEnd = max($page, (int) ($payload['page_end'] ?? $page));
            $maxPages = max(1, min(20, (int) ($payload['max_pages_per_run'] ?? 1)));
            $protocol = $this->protocolForSource($source);
            $newDataPolicy = $this->sourceNewDataPolicy($source);
            $filters = ['page' => $page, 'limit' => $limit];
            foreach (['t', 'h', 'wd', 'ids', 'play'] as $filter) {
                if (isset($payload[$filter]) && trim((string) $payload[$filter]) !== '') {
                    $filters[$filter] = mb_substr(trim((string) $payload[$filter]), 0, $filter === 'ids' ? 1000 : 100);
                }
            }
            if (($source['resource_type'] ?? 'video') === 'scenario') {
                return $this->runScenarioJob($jobId, $job, $source, $payload, $filters, $page, $pageEnd, $maxPages);
            }
            $mapping = json_decode((string) ($source['category_mapping'] ?? '{}'), true);
            $mapping = is_array($mapping) ? $mapping : [];
            $defaultCategory = (int) Db::table('ffx_categories')->where('content_type', 'media')->where('status', 'published')->order('sort_order')->value('id');
            $lastRemotePage = $page;
            $remotePageCount = $pageEnd;
            for ($offset = 0; $offset < $maxPages && $page + $offset <= $pageEnd; $offset++) {
                $currentPage = $page + $offset;
                $filters['page'] = $currentPage;
                $params = $this->normalizer->requestParams($protocol, 'video_detail', $filters);
                if (!empty($source['credential_ref'])) $params['key'] = mb_substr((string) $source['credential_ref'], 0, 255);
                $response = $this->http->fetch((string) $source['endpoint'], $params);
                $envelope = $this->normalizer->envelope($response, $protocol);
                $remotePageCount = max(1, (int) $envelope['pagecount']);
                $lastRemotePage = $currentPage;
                $rows = $envelope['rows'];
                if ($rows === []) {
                    $remotePageCount = $currentPage;
                    break;
                }
                foreach ($rows as $row) {
                    $processed++;
                    try {
                        $wasCreated = $this->importMedia((int) $source['id'], $row, $mapping, $defaultCategory, $newDataPolicy);
                        $wasCreated ? $created++ : $updated++;
                    } catch (CollectionSkipException) {
                        continue;
                    } catch (Throwable $exception) {
                        $errors++;
                        if (count($errorMessages) < 5) {
                            $errorMessages[] = '#' . $processed . ' ' . mb_substr($exception->getMessage(), 0, 300);
                        }
                    }
                }
                if ($protocol === 'feifei_json') {
                    try {
                        $scenarioParams = $this->normalizer->requestParams($protocol, 'scenario', $filters);
                        if (!empty($source['credential_ref'])) $scenarioParams['key'] = mb_substr((string) $source['credential_ref'], 0, 255);
                        $scenarioEnvelope = $this->normalizer->envelope($this->http->fetch((string) $source['endpoint'], $scenarioParams), $protocol);
                        foreach ($scenarioEnvelope['rows'] as $scenarioRow) $this->importScenario((int) $source['id'], $scenarioRow);
                    } catch (Throwable $scenarioException) {
                        if (count($errorMessages) < 5) $errorMessages[] = '剧情接口：' . mb_substr($scenarioException->getMessage(), 0, 260);
                    }
                }
                if ($currentPage >= $remotePageCount || isset($payload['ids'])) break;
                $pause = $this->settings->int('admin.collection.collect_time', 0, 0, 5);
                if ($pause > 0) sleep($pause);
            }
            $totalProcessed = (int) $job['processed_count'] + $processed;
            $totalCreated = (int) $job['created_count'] + $created;
            $totalUpdated = (int) $job['updated_count'] + $updated;
            $totalErrors = (int) $job['error_count'] + $errors;
            $hasMore = !isset($payload['ids']) && $lastRemotePage < min($pageEnd, $remotePageCount);
            if ($hasMore) {
                $payload['page'] = $lastRemotePage + 1;
            }
            $result = ['processed' => $totalProcessed, 'created' => $totalCreated, 'updated' => $totalUpdated, 'errors' => $totalErrors, 'next_page' => $hasMore ? $lastRemotePage + 1 : 0];
            Db::table('ffx_collection_jobs')->where('id', $jobId)->update([
                'state' => $hasMore ? 'queued' : ($totalErrors > 0 ? 'completed_with_errors' : 'completed'),
                'cursor_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'processed_count' => $totalProcessed, 'created_count' => $totalCreated, 'updated_count' => $totalUpdated,
                'error_count' => $totalErrors, 'finished_at' => $hasMore ? null : gmdate('Y-m-d H:i:s'),
                'error_message' => $errorMessages === [] ? null : implode("\n", $errorMessages),
            ]);
            if ($created + $updated > 0) $this->frontendCache->invalidateHome();
            return $result;
        } catch (Throwable $exception) {
            Db::table('ffx_collection_jobs')->where('id', $jobId)->update([
                'state' => 'failed', 'processed_count' => $processed, 'created_count' => $created,
                'updated_count' => $updated, 'error_count' => $errors + 1,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000), 'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($created + $updated > 0) $this->frontendCache->invalidateHome();
            throw $exception;
        }
    }

    /**
     * Read-only upstream browser used by the FeiFei-style collection console.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $filters
     * @return array{page:int,pagecount:int,limit:int,total:int,categories:array<int,array<string,mixed>>,items:array<int,array<string,mixed>>,protocol:string}
     */
    public function browse(array $source, array $filters = []): array
    {
        $this->guardEnabled();
        $protocol = $this->protocolForSource($source);
        $params = $this->normalizer->requestParams($protocol, $protocol === 'maccms_json' ? 'video_list' : 'video_detail', $filters);
        if (!empty($source['credential_ref'])) $params['key'] = mb_substr((string) $source['credential_ref'], 0, 255);
        $response = $this->http->fetch((string) $source['endpoint'], $params);
        $envelope = $this->normalizer->envelope($response, $protocol);
        $items = [];
        foreach ($envelope['rows'] as $row) {
            $items[] = [
                'id' => (string) ($row['vod_id'] ?? $row['id'] ?? ''),
                'name' => mb_substr(trim((string) ($row['vod_name'] ?? $row['name'] ?? $row['title'] ?? '')), 0, 255),
                'type_id' => (string) ($row['type_id'] ?? $row['vod_cid'] ?? ''),
                'type_name' => mb_substr(trim((string) ($row['type_name'] ?? $row['list_name'] ?? $row['vod_class'] ?? $row['vod_type'] ?? '')), 0, 120),
                'time' => mb_substr(trim((string) ($row['vod_time'] ?? $row['vod_addtime'] ?? $row['last'] ?? '')), 0, 30),
                'remarks' => mb_substr(trim((string) ($row['vod_remarks'] ?? $row['vod_continu'] ?? $row['vod_state'] ?? '')), 0, 120),
                'play_from' => mb_substr(trim((string) ($row['vod_play_from'] ?? $row['vod_play'] ?? '')), 0, 255),
            ];
        }
        return [
            'page' => $envelope['page'], 'pagecount' => $envelope['pagecount'],
            'limit' => $envelope['limit'], 'total' => $envelope['total'],
            'categories' => $envelope['categories'],
            'items' => $items,
            'protocol' => $envelope['protocol'],
        ];
    }

    /**
     * Import episode scenarios without changing media fields or playback data.
     *
     * @param array<string, mixed> $filters
     * @return array{processed:int,imported:int,skipped:int,page:int,pagecount:int,next_page:int,protocol:string}
     */
    public function runScenarios(int $sourceId, array $filters = []): array
    {
        $this->guardEnabled();
        $source = Db::table('ffx_collection_sources')->where('id', $sourceId)->find();
        if ($source === null || (string) $source['status'] !== 'enabled') {
            throw new \RuntimeException('采集源不存在或未启用');
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $requestFilters = ['page' => $page, 'limit' => $limit];
        foreach (['t', 'h', 'wd', 'ids', 'play'] as $key) {
            if (isset($filters[$key]) && trim((string) $filters[$key]) !== '') {
                $requestFilters[$key] = mb_substr(trim((string) $filters[$key]), 0, $key === 'ids' ? 1000 : 100);
            }
        }
        $protocol = $this->protocolForSource($source);
        $resource = $protocol === 'feifei_json' ? 'scenario' : 'video_detail';
        $params = $this->normalizer->requestParams($protocol, $resource, $requestFilters);
        if (!empty($source['credential_ref'])) $params['key'] = mb_substr((string) $source['credential_ref'], 0, 255);
        $envelope = $this->normalizer->envelope($this->http->fetch((string) $source['endpoint'], $params), $protocol);
        $processed = $imported = $matched = 0;
        foreach ($envelope['rows'] as $row) {
            $processed++;
            $synced = $this->importScenario($sourceId, $row, (int) ($source['media_source_id'] ?? 0));
            if ($synced > 0) $matched++;
            $imported += $synced;
        }
        $pagecount = max(1, (int) $envelope['pagecount']);
        return [
            'processed' => $processed,
            'imported' => $imported,
            'skipped' => max(0, $processed - $matched),
            'page' => $page,
            'pagecount' => $pagecount,
            'next_page' => empty($requestFilters['ids']) && $page < $pagecount ? $page + 1 : 0,
            'protocol' => $protocol,
        ];
    }

    /**
     * Refresh scenarios for one local video through every enabled collection
     * reference attached to it. This powers the 7.4-style single and batch
     * “剧情获取” actions on the video list.
     *
     * @return array{processed:int,imported:int,skipped:int,sources:int}
     */
    public function runScenariosForMedia(int $mediaId): array
    {
        if ($mediaId < 1 || Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')->count() !== 1) {
            throw new \RuntimeException('影片不存在');
        }
        $refs = Db::table('ffx_external_refs')->alias('r')
            ->join(['ffx_collection_sources' => 's'], 's.id=r.source_id')
            ->where('r.entity_type', 'media')->where('r.entity_id', $mediaId)->where('s.status', 'enabled')
            ->field('r.source_id,r.external_id')->order('r.id')->select()->toArray();
        if ($refs === []) throw new \RuntimeException('该影片没有可用的采集来源标识');

        $processed = $imported = $skipped = 0;
        $targets = [];
        foreach ($refs as $ref) {
            $sourceId = (int) $ref['source_id'];
            $targets[$sourceId . ':' . $ref['external_id']] = ['source_id' => $sourceId, 'external_id' => (string) $ref['external_id']];
            $linked = Db::table('ffx_collection_sources')->where('resource_type', 'scenario')->where('media_source_id', $sourceId)->where('status', 'enabled')->column('id');
            foreach ($linked as $linkedSourceId) {
                $targets[(int) $linkedSourceId . ':' . $ref['external_id']] = ['source_id' => (int) $linkedSourceId, 'external_id' => (string) $ref['external_id']];
            }
        }
        foreach ($targets as $target) {
            $result = $this->runScenarios((int) $target['source_id'], ['ids' => (string) $target['external_id'], 'limit' => 100]);
            $processed += (int) $result['processed'];
            $imported += (int) $result['imported'];
            $skipped += (int) $result['skipped'];
        }
        return ['processed' => $processed, 'imported' => $imported, 'skipped' => $skipped, 'sources' => count($targets)];
    }

    /** @return array<int, array<string, mixed>> */
    private function extractRows(array $response): array
    {
        $rows = $response['list'] ?? $response['data'] ?? [];
        if (isset($rows['list']) && is_array($rows['list'])) {
            $rows = $rows['list'];
        }
        if (!is_array($rows)) {
            throw new \RuntimeException('采集响应缺少 list/data 数组');
        }
        return array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $row
     *  @param array<string, mixed> $mapping
     */
    private function importMedia(int $sourceId, array $row, array $mapping, int $defaultCategory, string $newDataPolicy = 'published'): bool
    {
        $incoming = $this->normalizer->media($row);
        $externalId = (string) $incoming['external_id'];
        $title = (string) $incoming['title'];
        if ($externalId === '' || $title === '') {
            throw new \RuntimeException('影片缺少 ID 或名称');
        }
        $incoming['metadata']['source_ref'] = $this->sourceIdentity->markerForSource(
            $sourceId, $externalId, (string) ($incoming['metadata']['source_ref'] ?? '')
        );
        if (empty($incoming['metadata']['inputer'])) $incoming['metadata']['inputer'] = 'json_' . $sourceId;
        $typeId = (string) $incoming['category_remote_id'];
        $categoryId = $this->categoryMap->resolve($mapping, $typeId, $defaultCategory);
        if ($categoryId < 1) {
            throw new CollectionSkipException('远程分类已设置为不绑定');
        }

        $existing = Db::table('ffx_external_refs')->where('source_id', $sourceId)->where('entity_type', 'media')->where('external_id', $externalId)->find();
        $mergeId = $existing === null ? $this->findMergeCandidate($incoming, $categoryId) : (int) $existing['entity_id'];
        $created = $mergeId < 1;
        if (!$created) {
            $locked = Db::table('ffx_media')->where('id', $mergeId)->value('metadata');
            $locked = is_array($locked) ? $locked : (json_decode((string) $locked, true) ?: []);
            if (($locked['inputer'] ?? '') === 'feifeicms') throw new CollectionSkipException('影片已锁定，跳过采集更新');
        }
        if ($created && $newDataPolicy === 'update_only') {
            throw new CollectionSkipException('资源库已设置为只更新，不新增');
        }
        $sourceSets = [[$incoming['play_from'], $incoming['play_url']]];
        if ((string) $incoming['down_url'] !== '') $sourceSets[] = [$incoming['down_from'] !== '' ? $incoming['down_from'] : 'down', $incoming['down_url']];
        $allowedPlayers = $this->allowedPlayers();
        $parsedSources = [];
        foreach ($sourceSets as [$play, $urls]) {
            foreach ($this->episodeParser->parse((string) $play, (string) $urls) as $sourceIndex => $source) {
                $parserKey = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', strtolower((string) $source['key'])) ?: 'source-' . ($sourceIndex + 1);
                if ($allowedPlayers !== [] && !in_array($parserKey, $allowedPlayers, true)) continue;
                $parsedSources[] = ['parser_key' => $parserKey, 'source' => $source];
            }
        }
        Db::transaction(function () use ($sourceId, $externalId, $title, $categoryId, $row, $incoming, $existing, $mergeId, $newDataPolicy, $parsedSources): void {
            $now = gmdate('Y-m-d H:i:s');
            $newStatus = $newDataPolicy === 'draft' ? 'draft' : 'published';
            $mediaData = [
                'category_id' => $categoryId, 'title' => $title,
                'original_title' => $incoming['original_title'], 'subtitle' => $incoming['subtitle'],
                'content' => $incoming['content'], 'poster_url' => $incoming['poster_url'], 'backdrop_url' => $incoming['backdrop_url'],
                'area' => $incoming['area'], 'language' => $incoming['language'], 'release_year' => $incoming['release_year'],
                'release_date' => $incoming['release_date'], 'episode_total' => $incoming['episode_total'],
                'episode_label' => $incoming['episode_label'], 'is_completed' => $incoming['is_completed'],
                'douban_id' => $incoming['douban_id'], 'imdb_id' => $incoming['imdb_id'],
                'rating' => $incoming['rating'], 'rating_count' => $incoming['rating_count'],
                'view_count' => $incoming['view_count'], 'like_count' => $incoming['like_count'], 'dislike_count' => $incoming['dislike_count'],
                'weight' => $incoming['weight'], 'access_mode' => $incoming['access_mode'], 'price_points' => $incoming['price_points'],
                'metadata' => json_encode($incoming['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $newStatus,
                'published_at' => $newStatus === 'published' ? $now : null,
                'updated_at' => $now, 'deleted_at' => null,
            ];
            if ($mergeId < 1) {
                $safeExternalId = trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $externalId), '-');
                if ($safeExternalId === '') {
                    $safeExternalId = substr(hash('sha256', $externalId), 0, 32);
                }
                $mediaData['slug'] = mb_substr('source-' . $sourceId . '-' . $safeExternalId, 0, 255);
                $mediaData['media_type'] = 'video';
                $mediaData['created_at'] = $now;
                $mediaId = (int) Db::table('ffx_media')->insertGetId($mediaData);
            } else {
                $mediaId = $mergeId;
                $local = Db::table('ffx_media')->where('id', $mediaId)->lock(true)->find() ?: [];
                $mediaData = $this->mergeMediaData($local, $mediaData, true);
                Db::table('ffx_media')->where('id', $mediaId)->update($mediaData);
            }

            if (Db::table('ffx_media_categories')->where('media_id', $mediaId)->where('category_id', $categoryId)->count() === 0) {
                Db::table('ffx_media_categories')->insert(['media_id' => $mediaId, 'category_id' => $categoryId, 'is_primary' => $mergeId < 1 ? 1 : 0, 'sort_order' => 0]);
            }

            // Never delete/recreate imported lines: episodes may have assets,
            // comments and watch history referencing their stable IDs. Empty
            // or filtered upstream playback must not erase local playback.
            $oldSources = Db::table('ffx_play_sources')->where('media_id', $mediaId)
                ->where('collection_source_id', $sourceId)->order('sort_order')->order('id')->select()->toArray();
            $byParser = [];
            foreach ($oldSources as $oldSource) $byParser[(string) $oldSource['parser_key']][] = $oldSource;
            $usedKeys = [];
            $sortBase = (int) Db::table('ffx_play_sources')->where('media_id', $mediaId)->max('sort_order') + 1;
            foreach ($parsedSources as $parsed) {
                $parserKey = $parsed['parser_key'];
                $source = $parsed['source'];
                $oldSource = isset($byParser[$parserKey]) ? array_shift($byParser[$parserKey]) : null;
                if ($oldSource !== null) {
                    $playSourceId = (int) $oldSource['id'];
                    Db::table('ffx_play_sources')->where('id', $playSourceId)->update([
                        'display_name' => mb_substr((string) $source['name'], 0, 120),
                        'status' => 'enabled', 'updated_at' => $now,
                    ]);
                } else {
                    $key = $this->uniqueSourceKey($mediaId, $parserKey, $sourceId, $usedKeys);
                    $usedKeys[$key] = true;
                    $playSourceId = Db::table('ffx_play_sources')->insertGetId([
                        'media_id' => $mediaId, 'source_key' => mb_substr($key, 0, 80), 'parser_key' => mb_substr($parserKey, 0, 80),
                        'collection_source_id' => $sourceId, 'display_name' => mb_substr((string) $source['name'], 0, 120),
                        'sort_order' => $sortBase++, 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $oldEpisodes = Db::table('ffx_episodes')->where('source_id', $playSourceId)->column('id', 'episode_no');
                foreach ($source['episodes'] as $episodeIndex => $episode) {
                    $episodeNo = $episodeIndex + 1;
                    $episodeData = [
                        'label' => mb_substr((string) $episode['label'], 0, 120),
                        'media_url' => (string) $episode['url'], 'sort_order' => $episodeIndex,
                        'status' => 'enabled', 'updated_at' => $now,
                    ];
                    $oldEpisodeId = (int) ($oldEpisodes[$episodeNo] ?? 0);
                    if ($oldEpisodeId > 0) {
                        Db::table('ffx_episodes')->where('id', $oldEpisodeId)->update($episodeData);
                        continue;
                    }
                    Db::table('ffx_episodes')->insert($episodeData + [
                        'media_id' => $mediaId, 'source_id' => $playSourceId,
                        'episode_no' => $episodeNo, 'published_at' => $now, 'created_at' => $now,
                    ]);
                }
            }

            if ($incoming['scenario'] !== null) $this->syncScenarioPayload($mediaId, $incoming['scenario'], $sourceId, $externalId);

            $refData = [
                'source_id' => $sourceId, 'entity_type' => 'media', 'entity_id' => $mediaId,
                'provider' => 'collection-' . $sourceId, 'external_id' => $externalId,
                'source_url' => (string) (($incoming['metadata']['source_ref'] ?? '') ?: ''),
                'checksum' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_synced_at' => $now, 'updated_at' => $now,
            ];
            if ($existing === null) {
                $refData['created_at'] = $now;
                Db::table('ffx_external_refs')->insert($refData);
            } else {
                Db::table('ffx_external_refs')->where('id', (int) $existing['id'])->update($refData);
            }
        });
        return $created;
    }

    /** @param array<string, mixed> $incoming */
    private function findMergeCandidate(array $incoming, int $categoryId): int
    {
        if (!$this->settings->bool('admin.collection.merge_enabled', true)) return 0;
        $title = $this->normalizeTitle((string) $incoming['title']);
        foreach ($this->settings->bool('admin.collection.merge_by_external_id', true) ? ['imdb_id', 'douban_id'] : [] as $field) {
            $value = trim((string) $incoming[$field]);
            if ($value === '') continue;
            $rows = Db::table('ffx_media')->where($field, $value)->whereNull('deleted_at')->select()->toArray();
            $matches = array_values(array_filter($rows, fn (array $row): bool => $this->normalizeTitle((string) $row['title']) === $title));
            if (count($matches) === 1) return (int) $matches[0]['id'];
        }
        $rows = Db::table('ffx_media')->where('category_id', $categoryId)->whereNull('deleted_at')->whereIn('title', $this->titleVariants((string) $incoming['title']))->limit(30)->select()->toArray();
        $matches = [];
        foreach ($rows as $row) {
            if ($this->settings->bool('admin.collection.merge_by_year', true) && $incoming['release_year'] !== null && $row['release_year'] !== null && (int) $incoming['release_year'] !== (int) $row['release_year']) continue;
            $metadata = is_string($row['metadata'] ?? null) ? json_decode((string) $row['metadata'], true) : ($row['metadata'] ?? []);
            $metadata = is_array($metadata) ? $metadata : [];
            if (!$this->identityCompatible((array) $incoming['metadata'], $metadata, 'director')) continue;
            if (!$this->identityCompatible((array) $incoming['metadata'], $metadata, 'actor')) continue;
            $score = 0;
            if ($incoming['release_year'] !== null && $row['release_year'] !== null && (int) $incoming['release_year'] === (int) $row['release_year']) $score += 3;
            if ($this->identityValues((string) ($incoming['metadata']['director'] ?? '')) !== [] && $this->identityValues((string) ($metadata['director'] ?? '')) !== []) $score += 3;
            if ($this->identityValues((string) ($incoming['metadata']['actor'] ?? '')) !== [] && $this->identityValues((string) ($metadata['actor'] ?? '')) !== []) $score += 2;
            if ((string) $incoming['area'] !== '' && (string) ($row['area'] ?? '') === (string) $incoming['area']) $score++;
            if ($score >= 2) $matches[] = $row;
        }
        return count($matches) === 1 ? (int) $matches[0]['id'] : 0;
    }

    /** @param array<string, mixed> $local @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeMediaData(array $local, array $incoming, bool $preserveReviewStatus = false): array
    {
        $incomingPublished = (string) ($incoming['status'] ?? 'draft') === 'published';
        $localPublished = (string) ($local['status'] ?? 'draft') === 'published';
        $result = [
            'updated_at' => $incoming['updated_at'],
            // 关闭“自动发布”时不能因为合并路径绕过设置；已经发布的旧数据也不能被采集降级。
            'status' => ($incomingPublished || $localPublished) ? 'published' : 'draft',
            'deleted_at' => null,
        ];
        if ($preserveReviewStatus) {
            $result['status'] = (string) ($local['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
            unset($result['published_at']);
        }
        if (!$preserveReviewStatus && $incomingPublished && !$localPublished && empty($local['published_at'])) {
            $result['published_at'] = $incoming['published_at'] ?? $incoming['updated_at'];
        }
        foreach (['episode_total', 'episode_label', 'is_completed'] as $field) if ($incoming[$field] !== null && $incoming[$field] !== '') $result[$field] = $incoming[$field];
        foreach (['original_title', 'subtitle', 'content', 'poster_url', 'backdrop_url', 'area', 'language', 'release_year', 'release_date', 'douban_id', 'imdb_id', 'rating', 'rating_count'] as $field) {
            if (($local[$field] ?? null) !== null && trim((string) ($local[$field] ?? '')) !== '' && (string) $local[$field] !== '0') continue;
            if ($incoming[$field] !== null && trim((string) $incoming[$field]) !== '') $result[$field] = $incoming[$field];
        }
        foreach (['view_count', 'like_count', 'dislike_count', 'weight'] as $field) $result[$field] = max((int) ($local[$field] ?? 0), (int) $incoming[$field]);
        if (($local['access_mode'] ?? 'free') === 'free' && $incoming['access_mode'] !== 'free') {
            $result['access_mode'] = $incoming['access_mode'];
            $result['price_points'] = $incoming['price_points'];
        }
        $oldMeta = is_string($local['metadata'] ?? null) ? json_decode((string) $local['metadata'], true) : ($local['metadata'] ?? []);
        $newMeta = json_decode((string) $incoming['metadata'], true);
        $mergedMeta = array_merge(is_array($newMeta) ? $newMeta : [], is_array($oldMeta) ? $oldMeta : []);
        $mergedMeta['source_ref'] = $this->sourceIdentity->merge(
            (string) ((is_array($oldMeta) ? $oldMeta : [])['source_ref'] ?? ''),
            (string) ((is_array($newMeta) ? $newMeta : [])['source_ref'] ?? '')
        );
        $result['metadata'] = json_encode($mergedMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $result;
    }

    /** @param array<string, mixed> $source */
    private function sourceNewDataPolicy(array $source): string
    {
        $policy = (string) ($source['new_data_policy'] ?? '');
        if (in_array($policy, ['published', 'draft', 'update_only'], true)) return $policy;
        return $this->settings->bool('admin.collection.auto_publish', true) ? 'published' : 'draft';
    }

    /** @param array<string, bool> $used */
    private function uniqueSourceKey(int $mediaId, string $base, int $sourceId, array $used): string
    {
        $candidate = mb_substr($base, 0, 80);
        $suffix = 1;
        while (isset($used[$candidate]) || Db::table('ffx_play_sources')->where('media_id', $mediaId)->where('source_key', $candidate)->count() > 0) {
            $suffix++;
            $tail = '-c' . $sourceId . '-' . $suffix;
            $candidate = mb_substr($base, 0, 80 - strlen($tail)) . $tail;
        }
        return $candidate;
    }

    /** @param array<string, mixed> $row */
    private function importScenario(int $sourceId, array $row, int $mediaSourceId = 0): int
    {
        $externalId = trim((string) ($row['vod_id'] ?? $row['id'] ?? ''));
        $referenceSourceId = $mediaSourceId > 0 ? $mediaSourceId : $sourceId;
        $ref = $externalId === '' ? null : Db::table('ffx_external_refs')->where('source_id', $referenceSourceId)->where('entity_type', 'media')->where('external_id', $externalId)->find();
        if ($ref === null && !empty($row['vod_reurl'])) {
            $ref = Db::table('ffx_external_refs')->where('source_id', $referenceSourceId)->where('entity_type', 'media')->where('source_url', (string) $row['vod_reurl'])->find();
        }
        if ($ref === null && !empty($row['vod_name'])) {
            $ids = Db::table('ffx_media')->where('title', trim((string) $row['vod_name']))->whereNull('deleted_at')->column('id');
            if (count($ids) === 1) $ref = ['entity_id' => (int) $ids[0]];
        }
        if ($ref === null) return 0;
        return $this->syncScenarioPayload((int) $ref['entity_id'], $row['vod_scenario'] ?? null, $sourceId, $externalId !== '' ? $externalId : (string) ($row['vod_reurl'] ?? ''));
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $source @param array<string,mixed> $payload @param array<string,mixed> $filters
     *  @return array<string,int|string>
     */
    private function runScenarioJob(int $jobId, array $job, array $source, array $payload, array $filters, int $page, int $pageEnd, int $maxPages): array
    {
        $processed = $imported = $skipped = 0;
        $lastPage = $page;
        $remotePageCount = $pageEnd;
        for ($offset = 0; $offset < $maxPages && $page + $offset <= $pageEnd; $offset++) {
            $currentPage = $page + $offset;
            $result = $this->runScenarios((int) $source['id'], array_replace($filters, ['page' => $currentPage]));
            $processed += (int) $result['processed'];
            $imported += (int) $result['imported'];
            $skipped += (int) $result['skipped'];
            $lastPage = $currentPage;
            $remotePageCount = max(1, (int) $result['pagecount']);
            if ((int) $result['next_page'] === 0 || isset($payload['ids'])) break;
            $pause = $this->settings->int('admin.collection.collect_time', 0, 0, 5);
            if ($pause > 0) sleep($pause);
        }
        $totalProcessed = (int) $job['processed_count'] + $processed;
        $totalImported = (int) $job['updated_count'] + $imported;
        $hasMore = !isset($payload['ids']) && $lastPage < min($pageEnd, $remotePageCount);
        if ($hasMore) $payload['page'] = $lastPage + 1;
        Db::table('ffx_collection_jobs')->where('id', $jobId)->update([
            'state' => $hasMore ? 'queued' : 'completed',
            'cursor_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processed_count' => $totalProcessed,
            'updated_count' => $totalImported,
            'finished_at' => $hasMore ? null : gmdate('Y-m-d H:i:s'),
            'error_message' => $skipped > 0 ? '本批有 ' . $skipped . ' 条剧情未匹配到本地视频' : null,
        ]);
        return ['processed' => $totalProcessed, 'created' => 0, 'updated' => $totalImported, 'errors' => (int) $job['error_count'], 'next_page' => $hasMore ? $lastPage + 1 : 0];
    }

    private function syncScenarioPayload(int $mediaId, mixed $payload, int $sourceId, string $externalId): int
    {
        $rows = $this->normalizer->scenarios($payload);
        if ($rows === []) return 0;
        $sourceRef = mb_substr('collection:' . $sourceId . ':' . $externalId, 0, 500);
        $seen = [];
        $synced = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $episode = (int) $row['episode_no'];
            $seen[] = $episode;
            $existing = Db::table('ffx_scenarios')->where('media_id', $mediaId)->where('episode_no', $episode)->find();
            $data = ['title' => $row['title'], 'content' => $row['content'], 'source_ref' => $sourceRef, 'status' => 'published', 'updated_at' => $now, 'deleted_at' => null];
            if ($existing === null) {
                Db::table('ffx_scenarios')->insert($data + ['media_id' => $mediaId, 'episode_no' => $episode, 'sort_order' => $episode, 'created_at' => $now]);
                $synced++;
            } elseif (str_starts_with((string) ($existing['source_ref'] ?? ''), 'collection:')) {
                Db::table('ffx_scenarios')->where('id', (int) $existing['id'])->update($data);
                $synced++;
            }
        }
        Db::table('ffx_scenarios')->where('media_id', $mediaId)->where('source_ref', $sourceRef)->whereNotIn('episode_no', $seen)->delete();
        return $synced;
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower((string) preg_replace('/[\s\x{00a0}\x{3000}]+/u', '', trim($title)));
    }

    /** @return array<int, string> */
    private function titleVariants(string $title): array
    {
        $title = trim($title);
        $compact = (string) preg_replace('/\s+(?=第[零一二两三四五六七八九十百0-9]+季(?:\s|$))/u', '', $title);
        return array_values(array_unique([$title, $compact]));
    }

    /** @param array<string, mixed> $incoming @param array<string, mixed> $local */
    private function identityCompatible(array $incoming, array $local, string $field): bool
    {
        $left = $this->identityValues((string) ($incoming[$field] ?? ''));
        $right = $this->identityValues((string) ($local[$field] ?? ''));
        return $left === [] || $right === [] || array_intersect($left, $right) !== [];
    }

    /** @return array<int, string> */
    private function identityValues(string $value): array
    {
        $parts = preg_split('/[,，、\/|;；]+/u', $value) ?: [];
        return array_values(array_unique(array_filter(array_map(fn (string $item): string => mb_strtolower((string) preg_replace('/\s+/u', '', trim($item))), $parts))));
    }

    /** @param array<string, mixed> $source */
    private function protocolForSource(array $source): string
    {
        $configured = (string) ($source['source_type'] ?? 'auto_json');
        if (in_array($configured, ['feifei_json', 'maccms_json'], true)) return $configured;
        $path = strtolower((string) parse_url((string) ($source['endpoint'] ?? ''), PHP_URL_PATH));
        return preg_match('#(?:api\.php/)?provide/vod#', $path) ? 'maccms_json' : 'feifei_json';
    }

    /** @return array<int, string> */
    private function allowedPlayers(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            preg_split('/[\s,\x{FF0C}]+/u', $this->settings->string('admin.collection.api_allowed_players')) ?: []
        ))));
    }

    private function guardEnabled(): void
    {
        if (!$this->settings->bool('admin.collection.enabled', true)) throw new \RuntimeException('系统已关闭采集功能');
    }
}
