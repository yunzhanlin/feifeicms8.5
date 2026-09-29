<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CsrfToken;
use app\service\WebInstaller;
use think\Response;
use Throwable;

final class Install extends BaseController
{
    public function __construct(\think\App $app, private readonly WebInstaller $installer, private readonly CsrfToken $csrf)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        if ($this->installer->isInstalled()) return $this->installedResponse();
        return view('/install/index', [
            'checks' => $this->installer->environmentChecks(),
            'ready' => $this->installer->environmentReady(),
            'csrf' => $this->csrf->get(),
            'error' => '',
            'values' => $this->defaults(),
            'version' => (string) config('feifei.version'),
        ]);
    }

    public function submit(): Response
    {
        if ($this->installer->isInstalled()) return $this->installedResponse();
        $values = array_merge($this->defaults(), $this->request->post());
        if (!$this->csrf->verify((string) ($values['_token'] ?? ''))) {
            return $this->form($values, '请求已过期，请刷新安装页后重试。', 419);
        }
        try {
            $result = $this->installer->install($values);
            return view('/install/success', [
                'result' => $result,
                'version' => (string) config('feifei.version'),
            ]);
        } catch (Throwable $exception) {
            return $this->form($values, $exception->getMessage(), 422);
        }
    }

    /** @param array<string, mixed> $values */
    private function form(array $values, string $error, int $status): Response
    {
        unset($values['db_pass'], $values['admin_password'], $values['admin_password_confirm'], $values['redis_password'], $values['meilisearch_key']);
        return view('/install/index', [
            'checks' => $this->installer->environmentChecks(),
            'ready' => $this->installer->environmentReady(),
            'csrf' => $this->csrf->get(),
            'error' => $error,
            'values' => array_merge($this->defaults(), $values),
            'version' => (string) config('feifei.version'),
        ], $status);
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        $scheme = $this->request->isSsl() ? 'https' : 'http';
        $host = (string) $this->request->host(true);
        return [
            'site_name' => '飞飞影视', 'app_url' => $host !== '' ? $scheme . '://' . $host : '',
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'feifeicms', 'db_user' => 'feifeicms',
            'cache_driver' => 'file', 'redis_host' => '127.0.0.1', 'redis_port' => '6379', 'redis_db' => '0',
            'search_driver' => 'mysql', 'meilisearch_host' => 'http://127.0.0.1:7700', 'admin_username' => 'admin', 'admin_email' => '',
        ];
    }

    private function installedResponse(): Response
    {
        return view('/install/installed', ['version' => (string) config('feifei.version')], 403);
    }
}
