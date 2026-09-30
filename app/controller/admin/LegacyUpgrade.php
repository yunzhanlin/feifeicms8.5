<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\plugin\Legacy43\Legacy43Migrator;
use app\plugin\Legacy43\Legacy43Source;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SearchIndexer;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\Response;
use Throwable;

final class LegacyUpgrade extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly Legacy43Migrator $migrator,
        private readonly AuditLogger $audit,
        private readonly SearchIndexer $searchIndexer,
        private readonly SiteSettings $settings,
    ) { parent::__construct($app); }

    public function index(): Response
    {
        $database = (array) config('database.connections.mysql');
        $manifest = require root_path() . 'app/plugin/Legacy43/manifest.php';
        return view('/admin/tools/legacy_upgrade', [
            'manifest' => $manifest,
            'modules' => $this->migrator->modules(),
            'defaults' => [
                'host' => (string) ($database['hostname'] ?? '127.0.0.1'),
                'port' => (string) ($database['hostport'] ?? '3306'),
                'database' => '', 'username' => (string) ($database['username'] ?? ''),
                'prefix' => 'ff_', 'charset' => 'utf8mb4', 'batch_size' => 100,
            ],
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function preflight(): Response
    {
        $this->guardCsrf();
        try {
            return json(['ok' => true, 'data' => $this->migrator->preflight($this->source())]);
        } catch (Throwable $exception) {
            return json(['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 500)], 422);
        }
    }

    public function batch(): Response
    {
        $this->guardCsrf();
        $module = (string) $this->request->post('module', '');
        $cursor = max(0, (int) $this->request->post('cursor', 0));
        $limit = max(10, min(500, (int) $this->request->post('batch_size', 100)));
        $dryRun = (string) $this->request->post('dry_run', '0') === '1';
        try {
            $result = $this->migrator->migrateBatch($this->source(), $module, $cursor, $limit, $dryRun);
            return json(['ok' => true, 'data' => $result]);
        } catch (Throwable $exception) {
            return json(['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 500)], 422);
        }
    }

    public function finish(): Response
    {
        $this->guardCsrf();
        $search = 'MySQL 搜索无需同步';
        try {
            if ($this->settings->string('admin.cache.search_driver', (string) config('feifei.search.driver', 'mysql')) === 'meilisearch') {
                $search = 'Meilisearch 已同步 ' . $this->searchIndexer->sync() . ' 条视频';
            }
            $this->audit->record('legacy43.upgrade_finish', 'migration', 'feifeicms43', null, ['search' => $search]);
            return json(['ok' => true, 'message' => '迁移完成；' . $search . '。请抽查分类、视频、播放线路和会员登录。']);
        } catch (Throwable $exception) {
            $this->audit->record('legacy43.upgrade_finish', 'migration', 'feifeicms43', null, ['search_error' => $exception->getMessage()]);
            return json(['ok' => true, 'message' => '业务数据已迁移，但搜索索引同步失败：' . mb_substr($exception->getMessage(), 0, 240)]);
        }
    }

    private function source(): Legacy43Source
    {
        $same = (string) $this->request->post('same_database', '0') === '1';
        if ($same) {
            $current = (array) config('database.connections.mysql');
            $config = [
                'host' => $current['hostname'] ?? '127.0.0.1', 'port' => $current['hostport'] ?? 3306,
                'database' => $current['database'] ?? '', 'username' => $current['username'] ?? '', 'password' => $current['password'] ?? '',
                'charset' => $current['charset'] ?? 'utf8mb4', 'prefix' => $this->request->post('prefix', 'ff_'),
            ];
        } else {
            $config = [
                'host' => $this->request->post('host', ''), 'port' => $this->request->post('port', 3306),
                'database' => $this->request->post('database', ''), 'username' => $this->request->post('username', ''),
                'password' => $this->request->post('password', ''), 'charset' => $this->request->post('charset', 'utf8mb4'),
                'prefix' => $this->request->post('prefix', 'ff_'),
            ];
        }
        return new Legacy43Source($config);
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
