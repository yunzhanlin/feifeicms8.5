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
    }
}
