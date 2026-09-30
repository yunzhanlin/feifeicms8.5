<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\CsrfToken;
use think\facade\Db;
use think\facade\Session;
use think\response\View;

final class Dashboard extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf)
    {
        parent::__construct($app);
    }

    public function index(): View
    {
        $databaseVersion = '未知';
        try {
            $versionRows = Db::query('SELECT VERSION() AS version');
            $databaseVersion = (string) ($versionRows[0]['version'] ?? '未知');
        } catch (\Throwable) {
            // 主页不应因环境信息读取失败而中断。
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $installPath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        $installPath = $installPath === '' || $installPath === '.' ? '/' : $installPath . '/';
        $serverName = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $serverAddress = (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
        $serverPort = (string) ($_SERVER['SERVER_PORT'] ?? '80');
        $gdVersion = '未安装';
        if (function_exists('gd_info')) {
            $gd = gd_info();
            $gdVersion = (string) ($gd['GD Version'] ?? '已安装');
        }

        return view('/admin/dashboard', [
            'adminName' => (string) Session::get('admin_name', ''),
            'csrf' => $this->csrf->get(),
            'environment' => [
                'support' => '271513820@qq.com',
                'serverAddress' => sprintf('%s (%s:%s)', $serverName, $serverAddress, $serverPort),
                'installPath' => $installPath,
                'serverSoftware' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'PHP built-in/FPM'),
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'database' => $databaseVersion,
                'databaseClient' => function_exists('mysqli_get_client_info') ? (string) mysqli_get_client_info() : 'PDO MySQL',
                'fileGetContents' => function_exists('file_get_contents'),
                'curl' => function_exists('curl_init'),
                'mbStrimwidth' => function_exists('mb_strimwidth'),
                'openssl' => extension_loaded('openssl'),
                'uploadLimit' => (string) (ini_get('upload_max_filesize') ?: '未知'),
                'gd' => $gdVersion,
                'version' => 'FeiFeiCMS ' . (string) config('feifei.version'),
            ],
        ]);
    }
}
