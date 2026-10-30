<?php
declare (strict_types = 1);

namespace app;

use think\Service;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        // Keep working with an older authoritative Composer map during upgrades.
        require_once __DIR__ . '/service/RuntimeCacheSettings.php';
        $this->app->make(\app\service\RuntimeCacheSettings::class)->apply();
        // Also runs after a first upgrade performed by the previous updater.
        \app\service\RetiredStaticFiles::retire($this->app->getRootPath());
        require_once __DIR__ . '/service/UsageReporting.php';
        require_once __DIR__ . '/command/UsageReport.php';
        $this->commands([\app\command\UsageReport::class]);
        $this->app->event->listen(\think\event\HttpEnd::class, function (\think\Response $response): void {
            if ($response->getCode() >= 400) return;
            $path = strtolower(trim($this->app->request->pathinfo(), '/'));
            if (preg_match('~^(?:health|install(?:\.php)?)(?:/|$)~', $path)) return;
            try { $this->app->make(\app\service\UsageReporting::class)->report('web'); }
            catch (\Throwable) { /* Optional aggregate statistics never affect the site. */ }
        });
    }
}
