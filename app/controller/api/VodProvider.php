<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use app\model\Category;
use app\model\Media;
use app\service\ResilientCache;
use think\facade\Db;
use think\Response;

/** Delimiter fields are assembled at the HTTP boundary only. */
final class VodProvider extends BaseController
{
    public function __construct(\think\App $app, private readonly ResilientCache $cache, private readonly \app\service\SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $action = strtolower((string) $this->request->param('ac', 'list'));
        $page = max(1, (int) $this->request->param('pg', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 20)));

        return in_array($action, ['detail', 'videolist'], true)
            ? $this->details($page, $limit)
            : $this->listing($page, $limit);
    }

    private function listing(int $page, int $limit): Response
    {
        $query = $this->filteredQuery();
        $total = (clone $query)->count();
        $rows = $query->field('id,category_id,title,original_title,douban_id,imdb_id,poster_url,release_year,release_date,metadata,area,language,published_at,view_count,episode_label,is_completed')
            ->order('published_at', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();

        return $this->jsonEnvelope($this->envelope(array_map([$this, 'mapBase'], $rows), $total, $page, $limit));
    }

    private function details(int $page, int $limit): Response
    {
        $query = $this->filteredQuery();
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->request->param('ids', '')))));
        if ($ids !== []) {
            $query->whereIn('id', array_slice($ids, 0, 100));
        }

        $total = (clone $query)->count();
        $rows = $query->order('published_at', 'desc')->order('id', 'desc')->page($page, $limit)->select()->toArray();
        // Public metadata is not an entitlement to download paid/VIP media.
        // URL export is opt-in and restricted to explicitly free videos.
        $exportable = $this->settings->bool('admin.collection.api_download_enabled')
            ? array_filter($rows, static fn (array $row): bool => ($row['access_mode'] ?? 'free') === 'free') : [];
        $playback = $exportable === [] ? [] : $this->playbackByMedia(array_column($exportable, 'id'));
        $result = [];
        foreach ($rows as $row) {
            $mapped = $this->mapBase($row);
            $mapped += $playback[(int) $row['id']] ?? ['vod_play_from' => '', 'vod_play_url' => ''];
            $mapped['vod_content'] = (string) ($row['content'] ?? '');
            $mapped['vod_blurb'] = (string) ($row['summary'] ?? '');
            $result[] = $mapped;
        }

        return $this->jsonEnvelope($this->envelope($result, $total, $page, $limit));
    }

    private function filteredQuery()
    {
        $query = Media::where('status', 'published')->whereNull('deleted_at');
        $typeId = max(0, (int) $this->request->param('t', 0));
        if ($typeId > 0) {
            $query->where('category_id', $typeId);
        }
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        if ($keyword !== '') {
            $query->whereLike('title', '%' . $keyword . '%');
        }
        $hours = min(8760, max(0, (int) $this->request->param('h', 0)));
        if ($hours > 0) {
            $query->where('updated_at', '>=', gmdate('Y-m-d H:i:s', time() - ($hours * 3600)));
        }
        return $query;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function envelope(array $rows, int $total, int $page, int $limit): array
    {
        return [
            'code' => 1,
            'msg' => '数据列表',
            'page' => $page,
            'pagecount' => $total === 0 ? 0 : (int) ceil($total / $limit),
            'limit' => $limit,
            'total' => $total,
            'list' => $rows,
            'class' => $this->cache->remember('api:vod-provider:categories:v1', 300, static fn (): array => Category::where('status', 'published')->where('content_type', 'media')
                ->field('id as type_id,parent_id as type_pid,name as type_name')->order('sort_order')->select()->toArray()),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function jsonEnvelope(array $payload): Response
    {
        $json = json($payload);
        $etag = '"' . hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '"';
        if (trim((string) $this->request->header('if-none-match', '')) === $etag) {
            return response('', 304)->header(['ETag' => $etag, 'Cache-Control' => 'public, max-age=15, stale-while-revalidate=30']);
        }
        return $json->header(['ETag' => $etag, 'Cache-Control' => 'public, max-age=15, stale-while-revalidate=30']);
    }

    /** @param array<string, mixed> $row */
    private function mapBase(array $row): array
    {
        $metadata = $row['metadata'] ?? [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        return [
            'vod_id' => (int) $row['id'],
            'type_id' => (int) ($row['category_id'] ?? 0),
            'vod_name' => (string) $row['title'],
            'vod_en' => (string) ($row['original_title'] ?? ''),
            'vod_douban_id' => (string) ($row['douban_id'] ?? ''),
            'vod_imdb_id' => (string) ($row['imdb_id'] ?? ''),
            'vod_pic' => (string) ($row['poster_url'] ?? ''),
            'vod_year' => (string) ($row['release_year'] ?? ''),
            'vod_area' => (string) ($row['area'] ?? ''),
            'vod_lang' => (string) ($row['language'] ?? ''),
            'vod_actor' => (string) ($metadata['actor'] ?? ''),
            'vod_director' => (string) ($metadata['director'] ?? ''),
            'vod_keywords' => (string) ($metadata['keywords'] ?? ''),
            'vod_tag' => (string) ($metadata['keywords'] ?? ''),
            'vod_class' => (string) ($metadata['type'] ?? ''),
            'vod_pubdate' => (string) (($row['release_date'] ?? '') ?: ($metadata['pubdate'] ?? '')),
            'vod_time' => (string) ($row['published_at'] ?? ''),
            'vod_hits' => (int) ($row['view_count'] ?? 0),
            'vod_remarks' => (string) ($row['episode_label'] ?? ((bool) ($row['is_completed'] ?? false) ? '已完结' : '')),
        ];
    }

    /** @param array<int, mixed> $mediaIds */
    private function playbackByMedia(array $mediaIds): array
    {
        $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
        if ($mediaIds === []) {
            return [];
        }

        $sources = Db::table('ffx_play_sources')->whereIn('media_id', $mediaIds)->where('status', 'enabled')
            ->order('media_id')->order('sort_order')->order('id')->select()->toArray();
        $sourceIds = array_column($sources, 'id');
        $episodes = $sourceIds === [] ? [] : Db::table('ffx_episodes')->whereIn('source_id', $sourceIds)->where('status', 'enabled')
            ->order('source_id')->order('sort_order')->order('episode_no')->select()->toArray();

        $episodesBySource = [];
        foreach ($episodes as $episode) {
            $episodesBySource[(int) $episode['source_id']][] = $episode;
        }
        $sourcesByMedia = [];
        foreach ($sources as $source) {
            $sourcesByMedia[(int) $source['media_id']][] = $source;
        }

        $result = [];
        foreach ($mediaIds as $mediaId) {
            $keys = [];
            $groups = [];
            foreach ($sourcesByMedia[$mediaId] ?? [] as $source) {
                $keys[] = (string) $source['source_key'];
                $items = [];
                foreach ($episodesBySource[(int) $source['id']] ?? [] as $episode) {
                    $items[] = (string) $episode['label'] . '$' . (string) $episode['media_url'];
                }
                $groups[] = implode('#', $items);
            }
            $result[$mediaId] = ['vod_play_from' => implode('$$$', $keys), 'vod_play_url' => implode('$$$', $groups)];
        }

        return $result;
    }
}
