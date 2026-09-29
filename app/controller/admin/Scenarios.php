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
        $query = Db::table('ffx_scenarios')->alias('s')
            ->join(['ffx_media' => 'm'], 'm.id=s.media_id')
            ->whereNull('s.deleted_at')->whereNull('m.deleted_at')
            ->field('s.*,m.title AS media_title')->order('s.id', 'desc');
        if ($keyword !== '') {
            $query->whereLike('m.title|s.title', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('s.status', $status);
        }
        if ($mediaId > 0) {
            $query->where('s.media_id', $mediaId);
        }
        return view('/admin/scenarios/index', [
            'items' => $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => array_filter(['wd' => $keyword, 'status' => $status, 'media_id' => $mediaId])]),
            'keyword' => $keyword, 'status' => $status, 'mediaId' => $mediaId, 'csrf' => $this->csrf->get(),
        ]);
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
        return redirect('/admin/scenarios/' . $id . '/edit');
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
        return redirect('/admin/scenarios/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireScenario($id);
        $data = ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_scenarios')->where('id', $id)->delete();
        $this->audit->record('scenario.delete', 'scenario', $id, $before, $data);
        return redirect('/admin/scenarios');
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
