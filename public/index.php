<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

use think\App;

// Take the request lease before loading any replaceable application/vendor code.
require_once __DIR__ . '/../app/service/UpdateMaintenance.php';
try {
    $updateLease = \app\service\UpdateMaintenance::enter(dirname(__DIR__));
} catch (\Throwable $error) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 3');
    exit('程序维护中，请稍后刷新；若持续出现，请联系管理员检查更新状态。');
}

// [ 应用入口文件 ]

try {
    require __DIR__ . '/../vendor/autoload.php';

    // 执行HTTP应用并响应
    $http = (new App())->http;
    $response = $http->run();
    $response->send();
    $http->end($response);
} finally {
    \app\service\UpdateMaintenance::leave($updateLease);
}

// A restricted panel can finish the response and use the current FPM process.
// Never begin the writer while this request still owns its shared lease.
if (class_exists(\app\service\ProgramUpdater::class, false)) {
    \app\service\ProgramUpdater::runDeferred();
}
