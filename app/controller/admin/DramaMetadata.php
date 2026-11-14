<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AdminSession;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\DramaMetadataImporter;
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
    ) {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $sourceId = (int) $this->request->get('source_id', 0);
        if ($sourceId > 0) return redirect('/admin/vod-tools/scenario-collect/resource?source_id=' . $sourceId);
        $id = (int) $this->request->get('media_id', 0);
        $keyword = mb_substr(trim((string) $this->request->get('wd', '')), 0, 100);
        $media = $id > 0 ? $this->media($id) : null;
        $local = $media === null && $keyword !== '' ? Db::table('ffx_media')->whereNull('deleted_at')
            ->whereLike('title', '%' . $keyword . '%')->order('id', 'desc')->limit(30)
            ->field('id,title,release_year,episode_total')->select()->toArray() : [];

        return $this->render($media, [
            'keyword' => $keyword, 'local' => $local,
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 200),
        ]);
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
        $season = (int) $this->request->post('season', 1);
        $offset = (int) $this->request->post('offset', 0);
        $plots = $type === 'tv' && (string) $this->request->post('plots', '') === '1';
        $cast = (string) $this->request->post('cast', '') === '1';
        $status = (string) $this->request->post('plot_status', '') === 'published' ? 'published' : 'draft';
        $values = compact('provider', 'type', 'season', 'offset', 'plots', 'cast', 'status', 'id', 'keyword');

        try {
            if ($id === '') {
                $results = $provider === 'tvzn' ? $this->tvzn->search($keyword) : $this->tmdb->search($keyword, $type);
                return $this->render($media, $values + ['results' => $results, 'searched' => true]);
            }
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) throw new \RuntimeException('请填写来源剧目 ID（纯数字）');
            if ($season < 0 || $season > 999 || $offset < 0 || $offset > 99000) throw new \RuntimeException('季数或集号加值超出范围');
            if (!$plots && !$cast) throw new \RuntimeException('请选择要采集的内容');
            $data = $provider === 'tvzn'
                ? $this->tvzn->preview($id, $plots, $cast)
                : $this->tmdb->preview($id, $type, $season, $plots, $cast);
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
            $counts = $this->importer->import((int) $media['id'], $preview['data']);
        } catch (\Throwable) {
            return $this->render($media, ['error' => '导入失败，请重新预览后重试'], 422);
        }
        Cache::delete($key);
        $this->audit->record('drama_metadata.import', 'media', (int) $media['id'], null, $counts + ['provider' => $preview['data']['provider']]);
        $message = sprintf('新增剧情 %d 集（跳过 %d 集），新增演员 %d 人、角色 %d 个、人物关系 %d 条',
            $counts['plots'], $counts['plots_skipped'], $counts['actors'], $counts['roles'], $counts['credits']);
        return redirect('/admin/vod-tools/drama-metadata?media_id=' . (int) $media['id'] . '&message=' . rawurlencode($message));
    }

    private function render(?array $media, array $values, int $status = 200): Response
    {
        return view(dirname(__DIR__, 2) . '/admin_views/drama_metadata.tpl', $values + [
            'media' => $media, 'local' => [], 'keyword' => (string) ($media['title'] ?? ''), 'csrf' => $this->csrf->get(),
            'provider' => 'tvzn', 'type' => 'tv', 'season' => 1, 'offset' => 0, 'plots' => true, 'cast' => true, 'status' => 'draft',
            'id' => '', 'preview' => [], 'previewToken' => '', 'results' => [], 'searched' => false, 'message' => '', 'error' => '',
        ], $status);
    }

    private function media(int $id): array
    {
        $media = Db::table('ffx_media')->where('id', $id)->whereNull('deleted_at')->field('id,title,release_year,episode_total')->find();
        if ($media === null) throw new HttpException(404, '找不到本地影片');
        return $media;
    }

    private function guard(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
