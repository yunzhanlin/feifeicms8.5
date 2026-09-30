<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\MeilisearchClientFactory;
use app\service\SearchIndexer;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Cache;
use think\facade\Db;
use think\Response;
use Throwable;

final class System extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SearchIndexer $indexer, private readonly SiteSettings $settings, private readonly MeilisearchClientFactory $searchClient)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $cache = ['ok' => false, 'driver' => (string) config('cache.default')];
        try {
            Cache::set('admin:system:probe', 'ok', 10);
            $cache['ok'] = Cache::get('admin:system:probe') === 'ok';
            Cache::delete('admin:system:probe');
        } catch (Throwable $exception) {
            $cache['error'] = $exception->getMessage();
        }
        $search = ['ok' => true, 'driver' => $this->settings->string('admin.cache.search_driver', (string) config('feifei.search.driver'))];
        if ($search['driver'] === 'meilisearch') {
            try {
                $health = $this->searchClient->client()->health();
                $search['ok'] = ($health['status'] ?? null) === 'available';
            } catch (Throwable $exception) {
                $search = ['ok' => false, 'driver' => 'meilisearch', 'error' => $exception->getMessage()];
            }
        }
        return view('/admin/system/index', [
            'schema' => Db::table('ffx_schema_versions')->order('version', 'desc')->find(),
            'cache' => $cache, 'search' => $search,
            'jobs' => Db::table('ffx_jobs')->order('id', 'desc')->limit(50)->select()->toArray(),
            'audits' => Db::table('ffx_audit_logs')->alias('a')->leftJoin(['ffx_admins' => 'ad'], 'ad.id = a.admin_id')
                ->field('a.*,ad.username')->order('a.id', 'desc')->limit(50)->select()->toArray(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function clearCache(): Response
    {
        $this->guardCsrf();
        Cache::clear();
        $this->audit->record('system.cache_clear', 'system', 'cache');
        return redirect('/admin/system');
    }

    public function rebuildSearch(): Response
    {
        $this->guardCsrf();
        $count = $this->indexer->sync();
        $indexName = $this->searchClient->index();
        Db::table('ffx_search_state')->where('index_name', $indexName)->delete();
        Db::table('ffx_search_state')->insert([
            'index_name' => $indexName, 'indexed_count' => $count,
            'pending_count' => 0, 'last_success_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->audit->record('system.search_rebuild', 'system', 'search', null, ['count' => $count]);
        return redirect('/admin/system');
    }

    public function retryJob(int $id): Response
    {
        $this->guardCsrf();
        $job = Db::table('ffx_jobs')->where('id', $id)->find();
        if ($job === null) {
            throw new HttpException(404, '任务不存在');
        }
        Db::table('ffx_jobs')->where('id', $id)->update(['state' => 'queued', 'reserved_at' => null, 'finished_at' => null, 'error_message' => null, 'available_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('job.retry', 'job', $id, $job, ['state' => 'queued']);
        return redirect('/admin/system');
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
