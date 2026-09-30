<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CollectionRunner;
use app\service\CollectionCategoryMap;
use app\service\CsrfToken;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;
use Throwable;

final class Collections extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly CollectionRunner $runner, private readonly CollectionCategoryMap $categoryMap, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $resourceType = $this->resourceType((string) $this->request->get('type', 'video'));
        return view('/admin/collections/index', [
            'sources' => Db::table('ffx_collection_sources')->where('resource_type', $resourceType)->order('id', 'desc')->select()->toArray(),
            'resumeJob' => Db::table('ffx_collection_jobs')->whereIn('state', ['queued', 'running'])->order('id', 'desc')->find(),
            'resourceType' => $resourceType,
            'resourceLabel' => $resourceType === 'scenario' ? '剧情' : '视频',
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function jobs(): Response
    {
        return view('/admin/collections/jobs', [
            'jobs' => Db::table('ffx_collection_jobs')->alias('j')->leftJoin(['ffx_collection_sources' => 's'], 's.id = j.source_id')
                ->field('j.*,s.name as source_name')->order('j.id', 'desc')->limit(100)->select()->toArray(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, $this->resourceType((string) $this->request->get('type', 'video')));
    }

    public function edit(int $id): Response
    {
        $item = $this->requireSource($id);
        return $this->form($item, $this->resourceType((string) ($item['resource_type'] ?? 'video')));
    }

    public function resource(int $id): Response
    {
        $source = $this->requireSource($id);
        if (($source['resource_type'] ?? 'video') === 'scenario') {
            return redirect('/admin/vod-tools/scenario-collect?source_id=' . $id);
        }
        $filters = [
            'page' => max(1, (int) $this->request->get('pg', 1)),
            't' => max(0, (int) $this->request->get('t', 0)),
            'h' => max(0, min(8760, (int) $this->request->get('h', 0))),
            'wd' => mb_substr(trim((string) $this->request->get('wd', '')), 0, 100),
        ];
        $error = '';
        $upstream = ['page' => 1, 'pagecount' => 1, 'limit' => 20, 'total' => 0, 'categories' => [], 'items' => [], 'protocol' => (string) $source['source_type']];
        try {
            $upstream = $this->runner->browse($source, $filters);
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 1000);
        }
        $mapping = json_decode((string) ($source['category_mapping'] ?? '{}'), true);
        $mapping = is_array($mapping) ? $mapping : [];
        $displayMapping = array_replace($this->suggestMapping($upstream['categories'], $this->localCategories()), $mapping);
        return view('/admin/collections/resource', [
            'source' => $source,
            'upstream' => $upstream,
            'mapping' => $displayMapping,
            'categories' => $this->localCategories(),
            'filters' => $filters,
            'error' => $error,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $data = $this->payload();
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $id = Db::table('ffx_collection_sources')->insertGetId($data);
        $this->audit->record('collection_source.create', 'collection_source', $id, null, $data);
        return redirect('/admin/collections/' . $id . '/edit');
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireSource($id);
        $data = $this->payload();
        $data['category_mapping'] = (string) ($before['category_mapping'] ?? '{}');
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_collection_sources')->where('id', $id)->update($data);
        $this->audit->record('collection_source.update', 'collection_source', $id, $before, $data);
        return redirect('/admin/collections/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireSource($id);
        Db::table('ffx_collection_sources')->where('id', $id)->update(['status' => 'disabled', 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('collection_source.disable', 'collection_source', $id, $before, ['status' => 'disabled']);
        return redirect('/admin/collections');
    }

    public function mapping(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireSource($id);
        $posted = (array) $this->request->post('mapping', []);
        $validLocalIds = array_map('intval', array_column($this->localCategories(), 'id'));
        $mapping = $this->categoryMap->normalize($posted, $validLocalIds);
        if ((string) $this->request->post('partial', '') === '1') {
            $existing = json_decode((string) ($before['category_mapping'] ?? '{}'), true);
            $existing = is_array($existing) ? $this->categoryMap->normalize($existing, $validLocalIds) : [];
            $mapping = array_replace($existing, $mapping);
        }
        $encoded = json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Db::table('ffx_collection_sources')->where('id', $id)->update(['category_mapping' => $encoded, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('collection_source.mapping', 'collection_source', $id, $before, ['category_mapping' => $encoded]);
        return redirect('/admin/collections/' . $id . '/resource');
    }

    public function queue(int $id): Response
    {
        $this->guardCsrf();
        $this->requireSource($id);
        $page = max(1, (int) $this->request->post('page', 1));
        $limit = min(100, max(1, (int) $this->request->post('limit', $this->settings->int('admin.collection.batch_size', 50, 1, 100))));
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', []))))), 0, 100);
        $hours = max(0, min(8760, (int) $this->request->post('h', 0)));
        $typeId = max(0, (int) $this->request->post('t', 0));
        $keyword = mb_substr(trim((string) $this->request->post('wd', '')), 0, 100);
        $player = mb_substr(trim((string) $this->request->post('play', '')), 0, 80);
        $scope = (string) $this->request->post('scope', $ids !== [] ? 'selected' : 'page');
        if (!in_array($scope, ['selected', 'page', 'all'], true)) $scope = 'page';
        if ($scope === 'selected' && $ids === []) throw new HttpException(422, '请先勾选要采集的资源');
        $cursor = ['page' => $page, 'limit' => $limit];
        if ($scope === 'selected' && $ids !== []) $cursor['ids'] = implode(',', $ids);
        if ($scope === 'all') {
            $cursor['page'] = 1;
            $cursor['page_end'] = max(1, min(100000, (int) $this->request->post('pagecount', 100000)));
            $cursor['max_pages_per_run'] = 5;
        }
        if ($hours > 0) $cursor['h'] = $hours;
        if ($typeId > 0) $cursor['t'] = $typeId;
        if ($keyword !== '') $cursor['wd'] = $keyword;
        if ($player !== '') $cursor['play'] = $player;
        $mode = $scope === 'all' ? match ($hours) { 24 => 'today', 168 => 'week', default => 'full' } : 'manual';
        $jobId = Db::table('ffx_collection_jobs')->insertGetId([
            'source_id' => $id, 'idempotency_key' => 'manual-' . $id . '-' . bin2hex(random_bytes(10)),
            'mode' => $mode, 'state' => 'queued',
            'cursor_value' => json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->record('collection_job.queue', 'collection_job', $jobId, null, ['source_id' => $id] + $cursor);
        if ((int) $this->request->post('execute_now', 1) === 1) {
            try {
                $result = $this->runner->run((int) $jobId);
                $this->audit->record('collection_job.execute', 'collection_job', $jobId, null, $result);
            } catch (Throwable $exception) {
                Db::table('ffx_collection_jobs')->where('id', $jobId)->where('state', 'queued')->update([
                    'state' => 'failed', 'error_count' => 1,
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'finished_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->audit->record('collection_job.fail', 'collection_job', $jobId, null, ['error' => $exception->getMessage()]);
            }
        }
        return redirect($scope === 'all' ? '/admin/collections#collection-jobs' : '/admin/collections/' . $id . '/resource?pg=' . $page);
    }

    public function execute(int $jobId): Response
    {
        $this->guardCsrf();
        try {
            $result = $this->runner->run($jobId);
            $this->audit->record('collection_job.execute', 'collection_job', $jobId, null, $result);
        } catch (Throwable $exception) {
            $this->audit->record('collection_job.fail', 'collection_job', $jobId, null, ['error' => $exception->getMessage()]);
        }
        return redirect('/admin/collections');
    }

    public function retry(int $jobId): Response
    {
        $this->guardCsrf();
        $job = Db::table('ffx_collection_jobs')->where('id', $jobId)->find();
        if ($job === null) {
            throw new HttpException(404, '任务不存在');
        }
        Db::table('ffx_collection_jobs')->where('id', $jobId)->update(['state' => 'queued', 'error_message' => null, 'started_at' => null, 'finished_at' => null]);
        $this->audit->record('collection_job.retry', 'collection_job', $jobId, $job, ['state' => 'queued']);
        return redirect('/admin/collections');
    }

    private function form(?array $item, string $resourceType): Response
    {
        return view('/admin/collections/edit', [
            'item' => $item ?? ['id' => 0, 'name' => '', 'endpoint' => '', 'source_type' => $resourceType === 'scenario' ? 'feifei_json' : 'auto_json', 'resource_type' => $resourceType, 'media_source_id' => null, 'credential_ref' => '', 'category_mapping' => '{}', 'new_data_policy' => 'published', 'status' => 'disabled'],
            'videoSources' => Db::table('ffx_collection_sources')->where('resource_type', 'video')->order('name')->select()->toArray(),
            'csrf' => $this->csrf->get(), 'isNew' => $item === null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $endpoint = mb_substr(trim((string) $this->request->post('endpoint', '')), 0, 1000);
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new HttpException(422, '采集地址必须是 HTTP(S) URL');
        }
        $name = mb_substr(trim((string) $this->request->post('name', '')), 0, 255);
        if ($name === '') {
            throw new HttpException(422, '采集源名称不能为空');
        }
        $resourceType = $this->resourceType((string) $this->request->post('resource_type', 'video'));
        $mediaSourceId = null;
        if ($resourceType === 'scenario') {
            $candidate = max(0, (int) $this->request->post('media_source_id', 0));
            if ($candidate > 0) {
                $exists = Db::table('ffx_collection_sources')->where('id', $candidate)->where('resource_type', 'video')->count();
                if ($exists !== 1) throw new HttpException(422, '关联的视频资源库不存在');
                $mediaSourceId = $candidate;
            }
        }
        return [
            'name' => $name, 'endpoint' => $endpoint,
            'source_type' => in_array((string) $this->request->post('source_type', ''), ['auto_json', 'feifei_json', 'maccms_json'], true) ? (string) $this->request->post('source_type') : 'auto_json',
            'resource_type' => $resourceType,
            'media_source_id' => $mediaSourceId,
            'credential_ref' => (($ref = trim((string) $this->request->post('credential_ref', ''))) !== '') ? mb_substr($ref, 0, 255) : null,
            'category_mapping' => '{}',
            'new_data_policy' => in_array((string) $this->request->post('new_data_policy', ''), ['published', 'draft', 'update_only'], true)
                ? (string) $this->request->post('new_data_policy') : 'published',
            'status' => $this->request->post('status', 'disabled') === 'enabled' ? 'enabled' : 'disabled',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function localCategories(): array
    {
        return Db::table('ffx_categories')->where('content_type', 'media')->where('status', 'published')->whereNull('deleted_at')->order('sort_order')->select()->toArray();
    }

    /**
     * Suggest the four classic FeiFeiCMS video groups while keeping the final
     * choice visible and editable in the binding table.
     *
     * @param array<int, array<string, mixed>> $remoteCategories
     * @param array<int, array<string, mixed>> $localCategories
     * @return array<string, int>
     */
    private function suggestMapping(array $remoteCategories, array $localCategories): array
    {
        $localByName = [];
        foreach ($localCategories as $category) {
            $localByName[(string) $category['name']] = (int) $category['id'];
        }
        $parents = [];
        foreach ($remoteCategories as $category) {
            $parents[(string) $category['id']] = $category;
        }
        $suggestions = [];
        foreach ($remoteCategories as $category) {
            $id = (string) $category['id'];
            $name = (string) $category['name'];
            $parentName = (string) ($parents[(string) ($category['parent_id'] ?? '0')]['name'] ?? '');
            $haystack = $parentName . ' ' . $name;
            $localName = match (true) {
                str_contains($haystack, '动漫'), str_contains($haystack, '动画') => '动漫',
                str_contains($haystack, '综艺'), str_contains($haystack, '体育'), str_contains($haystack, '足球'), str_contains($haystack, '篮球'), str_contains($haystack, '网球'), str_contains($haystack, '斯诺克') => '综艺',
                str_contains($haystack, '剧'), str_contains($haystack, '短剧'), str_contains($haystack, '漫剧') => '电视剧',
                default => '电影',
            };
            if (isset($localByName[$localName])) {
                $suggestions[$id] = $localByName[$localName];
            }
        }
        return $suggestions;
    }

    private function requireSource(int $id): array
    {
        $row = Db::table('ffx_collection_sources')->where('id', $id)->find();
        if ($row === null) {
            throw new HttpException(404, '采集源不存在');
        }
        return $row;
    }

    private function resourceType(string $value): string
    {
        return $value === 'scenario' ? 'scenario' : 'video';
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
