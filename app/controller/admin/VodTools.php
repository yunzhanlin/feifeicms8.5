<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CollectionRunner;
use app\service\CsrfToken;
use app\service\DoubanComments;
use app\service\DoubanMetadata;
use think\facade\Db;
use think\Response;
use Throwable;

final class VodTools extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly AuditLogger $audit,
        private readonly DoubanMetadata $douban,
        private readonly DoubanComments $doubanComments,
        private readonly CollectionRunner $runner,
    ) {
        parent::__construct($app);
    }

    public function douban(): Response
    {
        $missing = $this->doubanCandidates()->count();
        $failures = Db::table('ffx_external_refs')->alias('r')
            ->leftJoin(['ffx_media' => 'm'], 'm.id = r.entity_id')
            ->where('r.provider', 'douban-metadata-failure')->where('r.entity_type', 'media')
            ->field('r.*,m.title,m.douban_id')->order('r.updated_at', 'desc')->limit(100)->select()->toArray();
        foreach ($failures as &$failure) $failure['detail'] = $this->jsonObject($failure['payload'] ?? null);
        unset($failure);
        return view('/admin/vod_tools/douban', [
            'total' => Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'")->count(),
            'missing' => $missing,
            'failures' => $failures,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function doubanNext(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $scope = (string) $this->request->post('scope', 'missing');
        if (!in_array($scope, ['missing', 'all'], true)) $scope = 'missing';
        $cursor = max(0, (int) $this->request->post('cursor', 0));
        $mediaId = max(0, (int) $this->request->post('media_id', 0));
        $query = $scope === 'missing' ? $this->doubanCandidates() : Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'");
        if ($mediaId > 0) $query->where('id', $mediaId);
        else $query->where('id', '>', $cursor);
        $media = $query->order('id')->find();
        if ($media === null) return json(['ok' => true, 'message' => '全部处理完成', 'data' => ['done' => true, 'cursor' => $cursor]]);
        try {
            $incoming = $this->douban->fetch((string) $media['douban_id']);
            $fields = $this->applyDoubanData($media, $incoming, $scope === 'missing');
            Db::table('ffx_external_refs')->where('provider', 'douban-metadata-failure')->where('entity_type', 'media')->where('external_id', (string) $media['id'])->delete();
            $this->audit->record('vod.douban_complete', 'media', (int) $media['id'], null, ['scope' => $scope, 'fields' => $fields]);
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：已补全 ' . count($fields) . ' 个字段', 'data' => ['done' => false, 'cursor' => (int) $media['id'], 'media_id' => (int) $media['id'], 'title' => $media['title'], 'douban_id' => $media['douban_id'], 'fields' => $fields]]);
        } catch (Throwable $exception) {
            $this->saveFailure((int) $media['id'], $exception->getMessage());
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：' . mb_substr($exception->getMessage(), 0, 160), 'data' => ['done' => false, 'failed' => true, 'cursor' => (int) $media['id'], 'media_id' => (int) $media['id']]]);
        }
    }

    public function duplicates(): Response
    {
        $mode = (string) $this->request->get('mode', 'all');
        if (!in_array($mode, ['all', 'same', 'conflict'], true)) $mode = 'all';
        $keyword = mb_substr(trim((string) $this->request->get('wd', '')), 0, 80);
        $groups = Db::query("SELECT douban_id,COUNT(*) duplicate_count,COUNT(DISTINCT title) title_count,GROUP_CONCAT(DISTINCT title ORDER BY title SEPARATOR ' / ') titles FROM ffx_media WHERE deleted_at IS NULL AND douban_id REGEXP '^[1-9][0-9]{4,11}$' GROUP BY douban_id HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC,douban_id LIMIT 500");
        $stats = ['all' => count($groups), 'same' => 0, 'conflict' => 0];
        $filtered = [];
        foreach ($groups as $group) {
            $kind = (int) $group['title_count'] > 1 ? 'conflict' : 'same';
            $stats[$kind]++;
            if ($mode !== 'all' && $mode !== $kind) continue;
            if ($keyword !== '' && !str_contains((string) $group['douban_id'], $keyword) && !str_contains(mb_strtolower((string) $group['titles']), mb_strtolower($keyword))) continue;
            $group['kind'] = $kind;
            $items = Db::table('ffx_media')->whereNull('deleted_at')->where('douban_id', (string) $group['douban_id'])->field('id,title,release_year,status,metadata,updated_at')->order('id')->select()->toArray();
            foreach ($items as &$item) $item['meta'] = $this->jsonObject($item['metadata'] ?? null);
            unset($item);
            $group['items'] = $items;
            $filtered[] = $group;
        }
        return view('/admin/vod_tools/duplicates', ['groups' => $filtered, 'stats' => $stats, 'mode' => $mode, 'keyword' => $keyword, 'csrf' => $this->csrf->get()]);
    }

    public function comments(): Response
    {
        return view('/admin/vod_tools/comments', [
            'total' => Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'")->count(),
            'imported' => Db::table('ffx_external_refs')->where('provider', 'douban-comment')->where('entity_type', 'comment')->count(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function commentsNext(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $cursor = max(0, (int) $this->request->post('cursor', 0));
        $mediaId = max(0, (int) $this->request->post('media_id', 0));
        $query = Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'");
        $mediaId > 0 ? $query->where('id', $mediaId) : $query->where('id', '>', $cursor);
        $media = $query->order('id')->find();
        if ($media === null) return json(['ok' => true, 'message' => '全部处理完成', 'data' => ['done' => true, 'cursor' => $cursor]]);
        try {
            $comments = $this->doubanComments->fetch((string) $media['douban_id']);
            $created = Db::transaction(function () use ($media, $comments): int {
                $created = 0;
                foreach ($comments as $content) {
                    $externalId = (string) $media['douban_id'] . ':' . hash('sha256', $content);
                    if (Db::table('ffx_external_refs')->where('provider', 'douban-comment')->where('entity_type', 'comment')->where('external_id', $externalId)->find() !== null) continue;
                    $now = gmdate('Y-m-d H:i:s');
                    $commentId = Db::table('ffx_comments')->insertGetId(['user_id' => null, 'parent_id' => null, 'target_type' => 'media', 'target_id' => (int) $media['id'], 'episode_id' => null, 'title' => '', 'content' => $content, 'author_name' => '豆瓣网友', 'ip_address' => null, 'status' => 'approved', 'is_pinned' => 0, 'like_count' => 0, 'dislike_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
                    Db::table('ffx_external_refs')->insert(['source_id' => null, 'entity_type' => 'comment', 'entity_id' => $commentId, 'provider' => 'douban-comment', 'external_id' => $externalId, 'source_url' => 'https://movie.douban.com/subject/' . $media['douban_id'] . '/comments', 'checksum' => hash('sha256', $content), 'payload' => json_encode(['media_id' => (int) $media['id']], JSON_UNESCAPED_UNICODE), 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    $created++;
                }
                return $created;
            });
            $this->audit->record('vod.douban_comments', 'media', (int) $media['id'], null, ['created' => $created]);
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：新增 ' . $created . ' 条评论', 'data' => ['done' => false, 'cursor' => (int) $media['id'], 'created' => $created]]);
        } catch (Throwable $exception) {
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：' . mb_substr($exception->getMessage(), 0, 160), 'data' => ['done' => false, 'failed' => true, 'cursor' => (int) $media['id']]]);
        }
    }

    public function scenarios(): Response
    {
        $withScenarios = (int) Db::table('ffx_scenarios')->whereNull('deleted_at')->value('COUNT(DISTINCT media_id)');
        return view('/admin/vod_tools/scenarios', [
            'total' => Db::table('ffx_scenarios')->whereNull('deleted_at')->count(),
            'mediaCount' => $withScenarios,
            'missing' => Db::table('ffx_media')->whereNull('deleted_at')->whereRaw('(COALESCE(episode_total,0)>0 OR EXISTS (SELECT 1 FROM ffx_episodes ep WHERE ep.media_id=ffx_media.id))')->whereRaw('NOT EXISTS (SELECT 1 FROM ffx_scenarios sc WHERE sc.media_id=ffx_media.id AND sc.deleted_at IS NULL)')->count(),
            'sources' => Db::table('ffx_collection_sources')->where('status', 'enabled')->order('id')->select()->toArray(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function scenariosRun(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $sourceId = max(0, (int) $this->request->post('source_id', 0));
        $filters = ['page' => max(1, (int) $this->request->post('page', 1)), 'limit' => min(100, max(1, (int) $this->request->post('limit', 20)))];
        $ids = mb_substr(trim((string) $this->request->post('ids', '')), 0, 1000);
        if ($ids !== '') $filters['ids'] = $ids;
        try {
            $result = $this->runner->runScenarios($sourceId, $filters);
            $this->audit->record('scenario.collect', 'collection_source', $sourceId, null, $result);
            return json(['ok' => true, 'message' => '处理 ' . $result['processed'] . ' 部资源，写入/更新 ' . $result['imported'] . ' 集剧情', 'data' => $result]);
        } catch (Throwable $exception) {
            return json(['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 200)], 422);
        }
    }

    private function doubanCandidates(): \think\db\Query
    {
        return Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'")->whereRaw("(COALESCE(TRIM(poster_url),'')='' OR COALESCE(TRIM(content),'')='' OR COALESCE(TRIM(imdb_id),'')='' OR release_date IS NULL OR COALESCE(rating,0)=0 OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.director')),'')='' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.actor')),'')='')");
    }

    /** @param array<string,mixed> $media @param array<string,mixed> $incoming @return array<int,string> */
    private function applyDoubanData(array $media, array $incoming, bool $onlyMissing): array
    {
        $columns = ['title', 'original_title', 'subtitle', 'content', 'poster_url', 'area', 'language', 'release_year', 'release_date', 'episode_total', 'imdb_id', 'rating', 'rating_count'];
        $metadataFields = ['type', 'tags', 'director', 'actor', 'writer', 'length', 'douban_score', 'source_ref'];
        $update = [];
        foreach ($columns as $field) {
            $value = $incoming[$field] ?? null;
            if ($value === null || $value === '' || $value === 0 || $value === 0.0) continue;
            if ($onlyMissing && !$this->emptyValue($media[$field] ?? null)) continue;
            $update[$field] = $value;
        }
        $meta = $this->jsonObject($media['metadata'] ?? null);
        foreach ($metadataFields as $field) {
            $value = $incoming[$field] ?? null;
            if ($value === null || $value === '' || $value === 0 || $value === 0.0) continue;
            if ($onlyMissing && !$this->emptyValue($meta[$field] ?? null)) continue;
            $meta[$field] = $value;
            $update['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($update !== []) {
            $update['updated_at'] = gmdate('Y-m-d H:i:s');
            Db::table('ffx_media')->where('id', (int) $media['id'])->update($update);
        }
        return array_values(array_filter(array_keys($update), static fn (string $field): bool => $field !== 'updated_at'));
    }

    private function saveFailure(int $mediaId, string $message): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = Db::table('ffx_external_refs')->where('provider', 'douban-metadata-failure')->where('entity_type', 'media')->where('external_id', (string) $mediaId)->find();
        $detail = $this->jsonObject($existing['payload'] ?? null);
        $payload = json_encode(['attempts' => (int) ($detail['attempts'] ?? 0) + 1, 'message' => mb_substr($message, 0, 500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($existing === null) Db::table('ffx_external_refs')->insert(['source_id' => null, 'entity_type' => 'media', 'entity_id' => $mediaId, 'provider' => 'douban-metadata-failure', 'external_id' => (string) $mediaId, 'source_url' => '', 'checksum' => '', 'payload' => $payload, 'last_synced_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        else Db::table('ffx_external_refs')->where('id', (int) $existing['id'])->update(['payload' => $payload, 'last_synced_at' => $now, 'updated_at' => $now]);
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0';
    }
}
