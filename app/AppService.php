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
        // Also runs after a first upgrade performed by the previous updater.
        \app\service\RetiredStaticFiles::retire($this->app->getRootPath());
    }
}
