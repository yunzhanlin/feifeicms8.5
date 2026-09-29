<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\SiteSettings;
use Meilisearch\Client;
use think\facade\Cache;
use think\facade\Db;
use think\Response;
use Throwable;

final class Health extends BaseController
{
    public function __construct(\think\App $app, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $checks = ['database' => $this->checkDatabase(), 'cache' => $this->checkCache(), 'search' => $this->checkSearch()];
        $healthy = $checks['database']['ok'] && $checks['cache']['ok'];
        return json([
            'ok' => $healthy,
            'version' => config('feifei.version_id'),
            'version_display' => config('feifei.version'),
            'source_reference' => config('feifei.source_reference'),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            Db::query('SELECT 1');
            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:' . bin2hex(random_bytes(6));
            Cache::set($key, 'ok', 10);
            $ok = Cache::get($key) === 'ok';
            Cache::delete($key);
            return ['ok' => $ok];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    private function checkSearch(): array
    {
        $driver = $this->settings->string('admin.cache.search_driver', (string) config('feifei.search.driver', 'mysql'));
        if ($driver !== 'meilisearch') {
            return ['ok' => true, 'driver' => 'mysql', 'optional' => true];
        }
        try {
            $defaults = (array) config('feifei.search.meilisearch');
            $health = (new Client(
                $this->settings->string('admin.cache.search_host', (string) ($defaults['host'] ?? 'http://127.0.0.1:7700')),
                $this->settings->string('admin.cache.search_key', (string) ($defaults['key'] ?? ''))
            ))->health();
            return ['ok' => ($health['status'] ?? null) === 'available', 'driver' => $driver, 'optional' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'driver' => $driver, 'optional' => true, 'error' => $exception->getMessage()];
        }
    }
}
