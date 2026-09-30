<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Scenarios extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $status = (string) $this->request->param('status', '');
        $mediaId = max(0, (int) $this->request->param('media_id', 0));
        if (!in_array($status, ['draft', 'published'], true)) {
            $status = '';
        }
        $mediaQuery = Db::table('ffx_media')->alias('m')->whereNull('m.deleted_at');
        $this->applyMediaFilters($mediaQuery, $keyword, $status, $mediaId);
        $total = (int) (clone $mediaQuery)->count();

        $query = Db::table('ffx_media')->alias('m')
            ->join(['ffx_scenarios' => 's'], 's.media_id=m.id AND s.deleted_at IS NULL')
            ->whereNull('m.deleted_at');
        $this->applyMediaFilters($query, $keyword, $status, $mediaId);
        $query->field(
            'm.id AS media_id,m.title AS media_title,m.episode_total,'
            . 'COUNT(s.id) AS scenario_count,MIN(s.episode_no) AS first_episode,MAX(s.episode_no) AS last_episode,'
            . "SUM(CASE WHEN s.status='published' THEN 1 ELSE 0 END) AS published_count,"
            . "SUM(CASE WHEN s.status='draft' THEN 1 ELSE 0 END) AS draft_count,"
            . 'CASE WHEN m.episode_total IS NULL OR m.episode_total<=COUNT(s.id) THEN 0 ELSE m.episode_total-COUNT(s.id) END AS missing_count,'
            . 'MAX(s.updated_at) AS updated_at'
        )->group('m.id,m.title,m.episode_total')->order('updated_at', 'desc')->order('m.id', 'desc');

        return view('/admin/scenarios/index', [
            'items' => $query->paginate(
                ['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => array_filter(['wd' => $keyword, 'status' => $status, 'media_id' => $mediaId])],
                $total
            ),
            'keyword' => $keyword, 'status' => $status, 'mediaId' => $mediaId, 'csrf' => $this->csrf->get(),
        ]);
    }

    public function manage(int $mediaId): Response
    {
        $media = $this->requireMedia($mediaId);
        return view('/admin/scenarios/manage', [
            'media' => $media,
            'items' => Db::table('ffx_scenarios')->where('media_id', $mediaId)->whereNull('deleted_at')
                ->order('episode_no')->order('sort_order')->order('id')->select()->toArray(),
            'message' => mb_substr(trim((string) $this->request->get('message', '')), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function saveMedia(int $mediaId): Response
    {
        $this->guardCsrf();
        $media = $this->requireMedia($mediaId);
        $current = Db::table('ffx_scenarios')->where('media_id', $mediaId)->whereNull('deleted_at')->order('id')->select()->toArray();
        $currentById = [];
        foreach ($current as $row) {
            $currentById[(int) $row['id']] = $row;
        }

        $rows = $this->scenarioRowsPayload();
        if ($rows === []) {
            return response('没有收到可保存的分集剧情', 422);
        }
        $seenIds = [];
        $seenEpisodes = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = max(0, (int) ($row['id'] ?? 0));
            $delete = !empty($row['delete']);
            if ($id > 0) {
                if (!isset($currentById[$id]) || isset($seenIds[$id])) {
                    return response('分集剧情数据不完整或已发生变化，请刷新后重试', 409);
                }
                $seenIds[$id] = true;
            }
            if ($delete) {
                if ($id > 0) {
                    $normalized[] = ['id' => $id, 'delete' => true];
                }
                continue;
            }

            $episode = max(1, min(100000, (int) ($row['episode_no'] ?? 1)));
            if (isset($seenEpisodes[$episode])) {
                return response('第' . $episode . '集重复，每部视频的集数必须唯一', 422);
            }
            $seenEpisodes[$episode] = true;
            $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 255);
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                return response('第' . $episode . '集的剧情内容不能为空', 422);
            }
            $normalized[] = [
                'id' => $id,
                'delete' => false,
                'episode_no' => $episode,
                'title' => $title !== '' ? $title : '第' . $episode . '集',
                'content' => $content,
                'source_ref' => mb_substr(trim((string) ($row['source_ref'] ?? '')), 0, 500),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'status' => ($row['status'] ?? '') === 'published' ? 'published' : 'draft',
            ];
        }
        if (count($seenIds) !== count($currentById)) {
            return response('页面中的原有剧情行不完整，请刷新后重试', 409);
        }

        $now = gmdate('Y-m-d H:i:s');
        $created = $updated = $deleted = 0;
        Db::transaction(function () use ($normalized, $mediaId, $now, &$created, &$updated, &$deleted): void {
            foreach ($normalized as $row) {
                $id = (int) $row['id'];
                if ($id > 0 && $row['delete']) {
                    Db::table('ffx_scenarios')->where('id', $id)->where('media_id', $mediaId)->delete();
                    $deleted++;
                }
            }
            // Move existing episode numbers out of the normal range first. This
            // permits swaps such as episode 1 <-> 2 without tripping the unique key.
            foreach ($normalized as $row) {
                $id = (int) $row['id'];
                if ($id > 0 && !$row['delete']) {
                    Db::table('ffx_scenarios')->where('id', $id)->where('media_id', $mediaId)
                        ->update(['episode_no' => 1000000 + $id]);
                }
            }
            foreach ($normalized as $row) {
                $id = (int) $row['id'];
                if ($row['delete']) {
                    continue;
                }
                $data = [
                    'episode_no' => $row['episode_no'], 'title' => $row['title'], 'content' => $row['content'],
                    'source_ref' => $row['source_ref'], 'sort_order' => $row['sort_order'], 'status' => $row['status'], 'updated_at' => $now,
                ];
                if ($id > 0) {
                    Db::table('ffx_scenarios')->where('id', $id)->where('media_id', $mediaId)->update($data);
                    $updated++;
                } else {
                    $data['media_id'] = $mediaId;
                    $data['created_at'] = $now;
                    Db::table('ffx_scenarios')->insert($data);
                    $created++;
                }
            }
        });

        $afterCount = (int) Db::table('ffx_scenarios')->where('media_id', $mediaId)->whereNull('deleted_at')->count();
        $this->audit->record('scenario.batch_update', 'media', $mediaId, ['count' => count($current)], [
            'count' => $afterCount, 'created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'title' => $media['title'],
        ]);
        $message = sprintf('已保存全部剧情：新增 %d 集，更新 %d 集，删除 %d 集', $created, $updated, $deleted);
        return redirect('/admin/scenarios/media/' . $mediaId . '?message=' . rawurlencode($message));
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->requireScenario($id));
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $data = $this->payload();
        if (($error = $this->validatePayload($data)) !== null) {
            return response($error, 422);
        }
        if ($this->episodeExists((int) $data['media_id'], (int) $data['episode_no'])) {
            return response('该影片的这一集剧情已存在', 422);
        }
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $id = (int) Db::table('ffx_scenarios')->insertGetId($data);
        $this->audit->record('scenario.create', 'scenario', $id, null, $data);
        return redirect('/admin/scenarios/media/' . (int) $data['media_id']);
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireScenario($id);
        $data = $this->payload();
        if (($error = $this->validatePayload($data)) !== null) {
            return response($error, 422);
        }
        if ($this->episodeExists((int) $data['media_id'], (int) $data['episode_no'], $id)) {
            return response('该影片的这一集剧情已存在', 422);
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_scenarios')->where('id', $id)->update($data);
        $this->audit->record('scenario.update', 'scenario', $id, $before, $data);
        return redirect('/admin/scenarios/media/' . (int) $data['media_id']);
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireScenario($id);
        $data = ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_scenarios')->where('id', $id)->delete();
        $this->audit->record('scenario.delete', 'scenario', $id, $before, $data);
        return redirect('/admin/scenarios/media/' . (int) $before['media_id']);
    }

    private function form(?array $scenario): Response
    {
        $mediaId = $scenario === null ? max(0, (int) $this->request->param('media_id', 0)) : (int) $scenario['media_id'];
        return view('/admin/scenarios/edit', [
            'scenario' => $scenario ?? ['id' => 0, 'media_id' => $mediaId, 'episode_no' => 1, 'title' => '', 'content' => '', 'source_ref' => '', 'sort_order' => 0, 'status' => 'draft'],
            'media' => Db::table('ffx_media')->whereNull('deleted_at')->order('title')->field('id,title')->select()->toArray(),
            'csrf' => $this->csrf->get(), 'isNew' => $scenario === null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $episode = max(1, (int) $this->request->post('episode_no', 1));
        $title = mb_substr(trim((string) $this->request->post('title', '')), 0, 255);
        return [
            'media_id' => max(0, (int) $this->request->post('media_id', 0)),
            'episode_no' => $episode,
            'title' => $title !== '' ? $title : '第' . $episode . '集',
            'content' => trim((string) $this->request->post('content', '')),
            'source_ref' => mb_substr(trim((string) $this->request->post('source_ref', '')), 0, 500),
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $this->request->post('status', '') === 'published' ? 'published' : 'draft',
        ];
    }

    private function validatePayload(array $data): ?string
    {
        if ((int) $data['media_id'] < 1 || Db::table('ffx_media')->where('id', (int) $data['media_id'])->whereNull('deleted_at')->count() !== 1) {
            return '请选择有效影片';
        }
        return trim((string) $data['content']) === '' ? '分集剧情内容不能为空' : null;
    }

    private function episodeExists(int $mediaId, int $episodeNo, int $exceptId = 0): bool
    {
        $query = Db::table('ffx_scenarios')->where('media_id', $mediaId)->where('episode_no', $episodeNo)->whereNull('deleted_at');
        if ($exceptId > 0) {
            $query->where('id', '<>', $exceptId);
        }
        return $query->count() > 0;
    }

    private function applyMediaFilters($query, string $keyword, string $status, int $mediaId): void
    {
        $query->whereExists(function ($subQuery): void {
            $subQuery->table('ffx_scenarios')->alias('sa')->fieldRaw('1')
                ->whereColumn('sa.media_id', '=', 'm.id')->whereNull('sa.deleted_at');
        });
        if ($keyword !== '') {
            $query->where(function ($scope) use ($keyword): void {
                $scope->whereLike('m.title', '%' . $keyword . '%')->whereExists(function ($subQuery) use ($keyword): void {
                    $subQuery->table('ffx_scenarios')->alias('sk')->fieldRaw('1')
                        ->whereColumn('sk.media_id', '=', 'm.id')->whereNull('sk.deleted_at')
                        ->whereLike('sk.title', '%' . $keyword . '%');
                }, 'OR');
            });
        }
        if ($status !== '') {
            $query->whereExists(function ($subQuery) use ($status): void {
                $subQuery->table('ffx_scenarios')->alias('ss')->fieldRaw('1')
                    ->whereColumn('ss.media_id', '=', 'm.id')->whereNull('ss.deleted_at')->where('ss.status', $status);
            });
        }
        if ($mediaId > 0) {
            $query->where('m.id', $mediaId);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function scenarioRowsPayload(): array
    {
        $json = trim((string) $this->request->post('rows_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new HttpException(422, '分集剧情数据格式无效');
            }
            return array_values(array_filter($decoded, 'is_array'));
        }
        $rows = $this->request->post('rows', []);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    private function requireMedia(int $mediaId): array
    {
        $media = Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')
            ->field('id,title,episode_total,status')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在');
        }
        return $media;
    }

    /** @return array<string, mixed> */
    private function requireScenario(int $id): array
    {
        $row = Db::table('ffx_scenarios')->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '分集剧情不存在');
        }
        return $row;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
