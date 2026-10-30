<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CollectionRunner;
use app\service\CsrfToken;
use app\service\DoubanComments;
use app\service\DoubanCommentImporter;
use app\service\DoubanCommentsPage;
use app\service\SiteSettings;
use think\facade\Session;
use app\service\DoubanMetadata;
use app\service\DoubanBatchScope;
use app\service\FrontendCache;
use think\facade\Db;
use think\Response;
use Throwable;

require_once dirname(__DIR__, 2) . '/service/DoubanCommentImporter.php';
require_once dirname(__DIR__, 2) . '/service/DoubanCommentsPage.php';

final class VodTools extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly AuditLogger $audit,
        private readonly DoubanMetadata $douban,
        private readonly DoubanComments $doubanComments,
        private readonly CollectionRunner $runner,
        private readonly FrontendCache $frontendCache,
    ) {
        parent::__construct($app);
    }

    public function douban(): Response
    {
        $batch = new DoubanBatchScope();
        $filters = $batch->normalize($this->request->get());
        $missing = $batch->candidates($filters)->count();
        $total = $batch->query($filters)->count();
        $failures = Db::table('ffx_external_refs')->alias('r')
            ->leftJoin(['ffx_media' => 'm'], 'm.id = r.entity_id')
            ->where('r.provider', 'douban-metadata-failure')->where('r.entity_type', 'media')
            ->field('r.*,m.title,m.douban_id')->order('r.updated_at', 'desc')->limit(100)->select()->toArray();
        foreach ($failures as &$failure) $failure['detail'] = $this->jsonObject($failure['payload'] ?? null);
        unset($failure);
        return view('/admin/vod_tools/douban', [
            'total' => $total,
            'missing' => $missing,
            'withoutId' => $batch->query($filters, false)->count() - $total,
            'categories' => $batch->categories(),
            'filters' => $filters,
            'progressOwner' => (int) \think\facade\Session::get('admin_id', 0),
            'failures' => $failures,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function doubanNext(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $batch = new DoubanBatchScope();
        $filters = $batch->normalize($this->request->post(), true);
        $scope = $filters['scope'];
        $cursor = max(0, (int) $this->request->post('cursor', 0));
        $mediaId = max(0, (int) $this->request->post('media_id', 0));
        $query = $scope === 'missing' ? $batch->candidates($filters) : $batch->query($filters);
        if ($mediaId > 0) $query->where('id', $mediaId);
        else $query->where('id', '>', $cursor);
        $media = $query->order('id')->find();
        $progress = ['batch_started_at' => $filters['batch_started_at'], 'upper_id' => $filters['upper_id']];
        if ($media === null) return json(['ok' => true, 'message' => '当前目录和时间范围已全部处理完成', 'data' => ['done' => true, 'cursor' => $cursor, 'batch' => $progress]]);
        try {
            $incoming = $this->douban->fetch((string) $media['douban_id']);
            $fields = $this->applyDoubanData($media, $incoming, $scope === 'missing');
            Db::table('ffx_external_refs')->where('provider', 'douban-metadata-failure')->where('entity_type', 'media')->where('external_id', (string) $media['id'])->delete();
            $this->audit->record('vod.douban_complete', 'media', (int) $media['id'], null, ['scope' => $scope, 'fields' => $fields]);
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：已补全 ' . count($fields) . ' 个字段', 'data' => ['done' => false, 'cursor' => (int) $media['id'], 'media_id' => (int) $media['id'], 'title' => $media['title'], 'douban_id' => $media['douban_id'], 'fields' => $fields, 'batch' => $progress]]);
        } catch (Throwable $exception) {
            $this->saveFailure((int) $media['id'], $exception->getMessage());
            return json(['ok' => true, 'message' => '#' . $media['id'] . ' ' . $media['title'] . '：' . mb_substr($exception->getMessage(), 0, 160), 'data' => ['done' => false, 'failed' => true, 'cursor' => (int) $media['id'], 'media_id' => (int) $media['id'], 'batch' => $progress]]);
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
        $keyword = mb_substr(trim((string)$this->request->get('q', '')), 0, 80);
        $query = $this->commentCandidates();
        $total = (clone $query)->count();
        if ($keyword !== '') $query->whereLike('title', '%' . addcslashes($keyword, '%_\\') . '%');
        $preview = Session::get('douban_comment_preview');
        if (!is_array($preview) || (int)($preview['expires_at'] ?? 0) < time()) {
            $preview = null;
            Session::delete('douban_comment_preview');
        }
        $data = [
            'total'=>$total,
            'imported'=>Db::table('ffx_external_refs')->where('provider','douban-comment')->where('entity_type','comment')->count(),
            'csrf'=>$this->csrf->get(), 'notice'=>(string)Session::pull('douban_comment_notice',''),
            'error'=>(string)Session::pull('douban_comment_error',''), 'ready'=>DoubanCommentImporter::ready(),
            'cookieConfigured'=>$this->app->make(SiteSettings::class)->string('admin.base.douban_cookie') !== '',
            'mediaId'=>max(0,(int)$this->request->get('media_id', $preview['media_id'] ?? 0)), 'preview'=>$preview,
            'keyword'=>$keyword, 'movies'=>$query->field('id,title,douban_id')->order('id','desc')->limit(20)->select()->toArray(),
            'adminId'=>(int)Session::get('admin_id',0),
            'adminName'=>(string)Session::get('admin_name','管理员'),
        ];
        return response(view('/admin/layout/header',$data)->getContent() . DoubanCommentsPage::render($data) . view('/admin/layout/footer',$data)->getContent());
    }

    public function commentsNext(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok'=>false,'message'=>'请求已过期'],419);
        $action = (string)$this->request->post('action','');
        if ($action !== '') return $this->commentsAction($action);
        if (!DoubanCommentImporter::ready()) return json(['ok'=>false,'message'=>'评论表需要升级，请先执行 php think feifei:schema:upgrade'],422);
        $cursor = max(0,(int)$this->request->post('cursor',0));
        $mediaId = max(0,(int)$this->request->post('media_id',0));
        $query = $this->commentCandidates()->where('id','>',$cursor);
        if ($mediaId > 0) $query->where('id',$mediaId);
        $media = $query->order('id')->find();
        if ($media === null) return json(['ok'=>true,'message'=>'全部处理完成','data'=>['done'=>true,'cursor'=>$cursor]]);
        try {
            $comments = $this->doubanComments->fetch((string)$media['douban_id']);
            $stats = $this->importComments($media,$comments);
            return json(['ok'=>true,'message'=>'#'.$media['id'].' '.$media['title'].'：'.$this->commentStats($stats),
                'data'=>['done'=>$mediaId > 0,'cursor'=>(int)$media['id']] + $stats]);
        } catch (Throwable $error) {
            // A failed request never advances the cursor or silently completes an empty import.
            return json(['ok'=>false,'message'=>'#'.$media['id'].'：'.mb_substr($error->getMessage(),0,180),
                'data'=>['done'=>false,'failed'=>true,'cursor'=>$cursor]],$error->getCode() === 429 ? 429 : 422);
        }
    }

    private function commentsAction(string $action): Response
    {
        $mediaId = max(0,(int)$this->request->post('media_id',0));
        try {
            if (!DoubanCommentImporter::ready()) throw new \RuntimeException('评论表需要升级，请先执行 php think feifei:schema:upgrade');
            if ($action === 'preview') {
                Session::delete('douban_comment_preview');
                $media = Db::table('ffx_media')->where('id',$mediaId)->whereNull('deleted_at')->find();
                if ($media === null) throw new \RuntimeException('影片不存在');
                $media = Db::table('ffx_media')->where('douban_id',(string)$media['douban_id'])->whereNull('deleted_at')->order('id','desc')->find();
                $mediaId = (int)$media['id'];
                $page = (int)$this->request->post('page',1);
                if ($page < 1 || $page > 101) throw new \RuntimeException('页码必须为1至101');
                $comments = $this->doubanComments->fetch((string)$media['douban_id'],($page-1)*20,(string)$this->request->post('sort','new_score'));
                $avatars = (new \app\service\DoubanAvatarStore())->cacheMany(array_column($comments,'avatar_url'));
                foreach ($comments as &$comment) $comment['display_avatar_url'] = $avatars[$comment['avatar_url']] ?? $comment['avatar_url'];
                unset($comment);
                Session::set('douban_comment_preview',['token'=>bin2hex(random_bytes(32)), 'media_id'=>$mediaId,
                    'title'=>$media['title'],'subject_id'=>(string)$media['douban_id'],'comments'=>$comments,'expires_at'=>time()+900]);
                Session::set('douban_comment_notice','已获取 '.count($comments).' 条短评，请核对后勾选入库。');
            } elseif ($action === 'import') {
                $preview = Session::get('douban_comment_preview');
                $token = (string)$this->request->post('preview_token','');
                if (!is_array($preview) || $token === '' || (int)($preview['expires_at'] ?? 0) < time() || !hash_equals((string)$preview['token'],$token)) throw new \RuntimeException('预览已失效，请重新获取后入库');
                $mediaId = (int)$preview['media_id'];
                $selected = $this->request->post('selected/a',[]);
                if (!is_array($selected) || count($selected) < 1 || count($selected) > 20) throw new \RuntimeException('请选择1至20条评论');
                $comments = [];
                foreach ($selected as $index) {
                    if (!is_scalar($index) || !preg_match('/^(?:0|[1-9]\d?)$/D',(string)$index) || !isset($preview['comments'][(int)$index])) throw new \RuntimeException('选择的评论无效，请重新预览');
                    $comments[(int)$index] = $preview['comments'][(int)$index];
                }
                $stats = $this->importComments(['id'=>$mediaId,'douban_id'=>$preview['subject_id']],array_values($comments));
                Session::delete('douban_comment_preview');
                Session::set('douban_comment_notice','#'.$mediaId.' '.$preview['title'].'：'.$this->commentStats($stats));
            } else throw new \RuntimeException('不支持的采集操作');
        } catch (Throwable $error) {
            Session::set('douban_comment_error',mb_substr($error->getMessage(),0,200));
        }
        return redirect('/admin/vod-tools/douban-comments'.($mediaId > 0 ? '?media_id='.$mediaId : ''));
    }

    private function importComments(array $media, array $comments): array
    {
        $stats = (new DoubanCommentImporter())->import((int)$media['id'],(string)$media['douban_id'],$comments);
        if ($stats['created'] + $stats['updated'] > 0) $this->frontendCache->invalidateHome();
        $this->audit->record('vod.douban_comments','media',(int)$media['id'],null,$stats);
        return $stats;
    }

    private function commentCandidates(): \think\db\Query
    {
        return Db::table('ffx_media')->whereNull('deleted_at')->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'")
            ->whereRaw('NOT EXISTS (SELECT 1 FROM ffx_media newer WHERE newer.douban_id=ffx_media.douban_id AND newer.deleted_at IS NULL AND newer.id>ffx_media.id)');
    }

    private function commentStats(array $stats): string
    {
        return '新增 '.$stats['created'].' 条，更新 '.$stats['updated'].' 条，跳过 '.$stats['skipped'].' 条';
    }

    public function scenarios(): Response
    {
        $withScenarios = (int) Db::table('ffx_scenarios')->whereNull('deleted_at')->value('COUNT(DISTINCT media_id)');
        $selectedSourceId = max(0, (int) $this->request->get('source_id', 0));
        return view('/admin/vod_tools/scenarios', [
            'total' => Db::table('ffx_scenarios')->whereNull('deleted_at')->count(),
            'mediaCount' => $withScenarios,
            'missing' => Db::table('ffx_media')->whereNull('deleted_at')->whereRaw('(COALESCE(episode_total,0)>0 OR EXISTS (SELECT 1 FROM ffx_episodes ep WHERE ep.media_id=ffx_media.id))')->whereRaw('NOT EXISTS (SELECT 1 FROM ffx_scenarios sc WHERE sc.media_id=ffx_media.id AND sc.deleted_at IS NULL)')->count(),
            'sources' => Db::table('ffx_collection_sources')->where('status', 'enabled')->orderRaw("CASE WHEN resource_type='scenario' THEN 0 ELSE 1 END")->order('name')->select()->toArray(),
            'selectedSourceId' => $selectedSourceId,
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
        $batch = new DoubanBatchScope();
        return $batch->candidates($batch->normalize([]));
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
            $this->frontendCache->invalidateHome();
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
