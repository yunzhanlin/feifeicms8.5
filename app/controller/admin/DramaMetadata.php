<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AdminSession;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\DramaMetadataImporter;
use app\service\DramaMetadataMatch;
use app\service\DramaMetadataScope;
use app\service\DoubanBatchScope;
use app\service\TmdbMetadata;
use app\service\TvznMetadata;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Cache;
use think\Response;

final class DramaMetadata extends BaseController
{
    private const PREVIEW = 'admin:drama-metadata:preview:';

    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly AuditLogger $audit,
        private readonly TvznMetadata $tvzn,
        private readonly TmdbMetadata $tmdb,
        private readonly DramaMetadataImporter $importer,
        private readonly DramaMetadataMatch $matcher,
        private readonly DramaMetadataScope $scope,
    ) {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $sourceId = (int) $this->request->get('source_id', 0);
        if ($sourceId > 0) return redirect('/admin/vod-tools/scenario-collect/resource?source_id=' . $sourceId);
        $id = (int) $this->request->get('media_id', 0);
        $media = $id > 0 ? $this->media($id) : null;
        $filters = $this->scope->filters($this->request->get());
        $local = $media === null ? $this->scope->query($filters)->field(DramaMetadataScope::MEDIA_FIELDS)->order('id', 'desc')
            ->paginate(['list_rows' => 30, 'path' => '/admin/vod-tools/drama-metadata', 'query' => $filters]) : null;

        return $this->render($media, [
            'keyword' => $media === null ? $filters['wd'] : (string) $media['title'],
            'local' => $local, 'filters' => $filters,
            'categories' => $media === null ? (new DoubanBatchScope())->categories() : [],
            'total' => $local?->total() ?? 0, 'progressOwner' => AdminSession::id(),
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 300),
        ]);
    }

    public function batchScript(): Response
    {
        return response((string) file_get_contents(dirname(__DIR__, 2) . '/admin_views/drama_metadata.js'))
            ->contentType('application/javascript')->header(['Cache-Control' => 'no-store']);
    }

    public function batchNext(): Response
    {
        $this->guard();
        $input = $this->request->post();
        $filters = $this->scope->filters($input);
        $mode = ($input['mode'] ?? '') === 'selected' ? 'selected' : 'all';
        $ids = trim((string) ($input['selected_ids'] ?? ''));
        if ($mode === 'selected' && !preg_match('/^[1-9][0-9]{0,16}(?:,[1-9][0-9]{0,16}){0,29}$/D', $ids)) {
            return json(['ok' => false, 'message' => '请勾选这一页要采集的影片'], 422);
        }
        [$plots, $cast, $status] = $this->contentOptions($input);
        if (!$plots && !$cast) return json(['ok' => false, 'message' => '请选择要采集的内容'], 422);
        $provider = ($input['provider'] ?? '') === 'tmdb' ? 'tmdb' : 'tvzn';
        $cursor = max(0, (int) ($input['cursor'] ?? 0));
        $query = $this->scope->query($filters);
        if ($mode === 'selected') $query->whereIn('id', explode(',', $ids));
        $upper = max(0, (int) ($input['upper_id'] ?? 0));
        if ($upper === 0) $upper = (int) (clone $query)->max('id');
        $media = $query->where('id', '>', $cursor)->where('id', '<=', $upper)
            ->order('id')->field(DramaMetadataScope::MEDIA_FIELDS)->find();

        if ($media === null) return json(['ok' => true, 'message' => '当前来源已处理完毕', 'data' => [
            'done' => true, 'cursor' => $cursor, 'upper_id' => $upper,
        ]]);

        $label = '#' . $media['id'] . ' ' . $media['title'] . ' · ' . strtoupper($provider) . '　';
        $progress = ['done' => false, 'cursor' => (int) $media['id'], 'upper_id' => $upper];
        try {
            $data = $this->matcher->collect($media, $provider, $plots, $cast);
        } catch (\RuntimeException $error) {
            if (preg_match('/HTTP (401|403|429)\b|API 密钥或 API 读取/u', $error->getMessage())) {
                return json(['ok' => false, 'message' => $label . $error->getMessage()], 422);
            }
            return json(['ok' => true, 'message' => $label . '读取失败，可重试或单部采集',
                'data' => $progress + ['state' => 'failed']]);
        } catch (\Throwable) {
            return json(['ok' => true, 'message' => $label . '读取失败，可重试或单部采集',
                'data' => $progress + ['state' => 'failed']]);
        }

        if ($data === null) return json(['ok' => true, 'message' => $label . '剧名、年份或季数无法唯一匹配，请单部确认',
            'data' => $progress + ['state' => 'review']]);
        if ($data['episodes'] === [] && $data['cast'] === []) {
            return json(['ok' => true, 'message' => $label . '来源暂无所选资料',
                'data' => $progress + ['state' => 'empty']]);
        }
        try {
            $counts = $this->save($media, $data + ['provider' => $provider, 'offset' => 0, 'plot_status' => $status]);
        } catch (\Throwable) {
            return json(['ok' => true, 'message' => $label . '入库失败，可重试或单部采集',
                'data' => $progress + ['state' => 'failed']]);
        }
        return json(['ok' => true, 'message' => $label . $this->summary($counts),
            'data' => $progress + ['state' => 'processed', 'counts' => $counts]]);
    }

    public function lookup(): Response
    {
        $this->guard();
        $media = $this->media((int) $this->request->post('media_id', 0));
        $provider = (string) $this->request->post('provider', 'tvzn') === 'tmdb' ? 'tmdb' : 'tvzn';
        $type = (string) $this->request->post('type', 'tv') === 'movie' && $provider === 'tmdb' ? 'movie' : 'tv';
        $keyword = mb_substr(trim((string) $this->request->post('title', '')), 0, 100);
        if ($keyword === '') $keyword = (string) $media['title'];
        $id = trim((string) $this->request->post('external_id', ''));
        $season = trim((string) $this->request->post('season', ''));
        $offset = (int) $this->request->post('offset', 0);
        [$plots, $cast, $status] = $this->contentOptions($this->request->post());
        $plots = $type === 'tv' && $plots;
        $collect = $this->request->post('intent', '') === 'collect';
        $values = compact('provider', 'type', 'season', 'offset', 'plots', 'cast', 'status', 'id', 'keyword');

        try {
            if ($season !== '' && !preg_match('/^[0-9]{1,3}$/D', $season)) throw new \RuntimeException('季数超出范围');
            $selectedSeason = $season === '' ? null : (int) $season;
            if ($id === '' && $collect && $keyword === $media['title']) {
                if (!$plots && !$cast) throw new \RuntimeException('请选择要采集的内容');
                if ($offset < 0 || $offset > 99000) throw new \RuntimeException('集号加值超出范围');
                $data = $this->matcher->collect($media, $provider, $plots, $cast, $type, $selectedSeason);
                if ($data !== null) return $this->collectResult($media, $data, $provider, $offset, $status);
            }
            if ($id === '') {
                $results = $provider === 'tvzn' ? $this->tvzn->search($keyword) : $this->tmdb->search($keyword, $type);
                if ($collect && !$plots && !$cast) throw new \RuntimeException('请选择要采集的内容');
                if (!$collect) return $this->render($media, $values + ['results' => $results, 'searched' => true]);
                // Leave custom titles and ambiguous matches in the preview workflow.
                if ($keyword !== $media['title']) return $this->render($media, $values + [
                    'results' => $results, 'searched' => true, 'error' => '请核对自定义搜索结果，再选择来源剧目',
                ]);
                return $this->render($media, $values + [
                    'results' => $results, 'searched' => true, 'error' => '请核对剧名和季数后选择来源剧目',
                ]);
            }
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) throw new \RuntimeException('请填写来源剧目 ID（纯数字）');
            if ($offset < 0 || $offset > 99000) throw new \RuntimeException('集号加值超出范围');
            if (!$plots && !$cast) throw new \RuntimeException('请选择要采集的内容');
            $data = $provider === 'tvzn'
                ? $this->tvzn->preview($id, $plots, $cast)
                : $this->tmdb->preview($id, $type, $selectedSeason ?? ($collect ? -1 : 1), $plots, $cast);
            if ($collect && ($data['season_found'] ?? true)) return $this->collectResult($media, $data, $provider, $offset, $status);
            $token = '';
            if ($data['cast'] !== [] || $data['episodes'] !== []) {
                $token = bin2hex(random_bytes(16));
                if (!Cache::set(self::PREVIEW . $token, [
                    'admin_id' => AdminSession::id(), 'media_id' => (int) $media['id'], 'media_title' => (string) $media['title'],
                    'data' => $data + ['provider' => $provider, 'offset' => $offset, 'plot_status' => $status],
                ], 900)) throw new \RuntimeException('无法保存采集预览，请稍后重试');
            }
            return $this->render($media, $values + ['preview' => $data, 'previewToken' => $token]);
        } catch (\RuntimeException $error) {
            return $this->render($media, $values + ['error' => $error->getMessage()], 422);
        } catch (\Throwable) {
            return $this->render($media, $values + ['error' => '采集请求失败，请检查来源服务后重试'], 422);
        }
    }

    public function apply(): Response
    {
        $this->guard();
        $media = $this->media((int) $this->request->post('media_id', 0));
        $token = (string) $this->request->post('preview_token', '');
        $key = self::PREVIEW . $token;
        $preview = preg_match('/^[0-9a-f]{32}$/D', $token) ? Cache::get($key) : null;
        if (!is_array($preview) || $preview['admin_id'] !== AdminSession::id()
            || $preview['media_id'] !== (int) $media['id'] || $preview['media_title'] !== (string) $media['title']) {
            return $this->render($media, ['error' => '预览已失效，请重新采集预览'], 409);
        }

        try {
            $counts = $this->save($media, $preview['data']);
        } catch (\Throwable) {
            return $this->render($media, ['error' => '导入失败，请重新预览后重试'], 422);
        }
        Cache::delete($key);
        return $this->redirectResult((int) $media['id'], $this->summary($counts));
    }

    private function collectResult(array $media, array $data, string $provider, int $offset, string $status): Response
    {
        if ($data['episodes'] === [] && $data['cast'] === []) {
            return $this->render($media, ['error' => '此剧目或季数没有可导入的资料'], 422);
        }
        try {
            $counts = $this->save($media, $data + ['provider' => $provider, 'offset' => $offset, 'plot_status' => $status]);
        } catch (\Throwable) {
            return $this->render($media, ['error' => '导入失败，请重试或使用采集预览'], 422);
        }
        return $this->redirectResult((int) $media['id'], $this->summary($counts));
    }

    private function save(array $media, array $data): array
    {
        $id = (int) $media['id'];
        $counts = $this->importer->import($id, $data + ['expected_title' => (string) $media['title']]);
        $this->audit->record('drama_metadata.import', 'media', $id, null, $counts + ['provider' => $data['provider']]);
        return $counts;
    }

    private function contentOptions(array $input): array
    {
        return [(string) ($input['plots'] ?? '') === '1', (string) ($input['cast'] ?? '') === '1',
            ($input['plot_status'] ?? '') === 'published' ? 'published' : 'draft'];
    }

    private function summary(array $counts): string
    {
        return sprintf('新增剧情 %d 集（跳过 %d 集），新增演员 %d 人、角色 %d 个、人物关系 %d 条',
            $counts['plots'], $counts['plots_skipped'], $counts['actors'], $counts['roles'], $counts['credits']);
    }

    private function redirectResult(int $id, string $message): Response
    {
        return redirect('/admin/vod-tools/drama-metadata?media_id=' . $id . '&message=' . rawurlencode($message));
    }

    private function render(?array $media, array $values, int $status = 200): Response
    {
        return view(dirname(__DIR__, 2) . '/admin_views/drama_metadata.tpl', $values + [
            'media' => $media, 'local' => null, 'total' => 0, 'keyword' => (string) ($media['title'] ?? ''), 'csrf' => $this->csrf->get(),
            'provider' => 'tvzn', 'type' => 'tv', 'season' => '', 'offset' => 0, 'plots' => true, 'cast' => true, 'status' => 'draft',
            'id' => '', 'preview' => [], 'previewToken' => '', 'results' => [], 'searched' => false, 'message' => '', 'error' => '',
        ], $status);
    }

    private function media(int $id): array
    {
        $media = Db::table('ffx_media')->where('id', $id)->whereNull('deleted_at')->field(DramaMetadataScope::MEDIA_FIELDS)->find();
        if ($media === null) throw new HttpException(404, '找不到本地影片');
        return $media;
    }

    private function guard(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
