<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
// $Id$

require_once __DIR__ . '/../app/service/PublicPathPolicy.php';

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (\app\service\PublicPathPolicy::isPrivate($path)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('页面不存在');
}

// Never serve a symlink to template/source files as a public static file.
$publicRoot = realpath(__DIR__);
$file = realpath(__DIR__ . '/' . ltrim(rawurldecode($path), '/'));
if ($file !== false && $publicRoot !== false && str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($file) && !is_link(__DIR__ . '/' . ltrim(rawurldecode($path), '/'))) {
    return false;
} else {
    $_SERVER["SCRIPT_FILENAME"] = __DIR__ . '/index.php';

    require __DIR__ . "/index.php";
}
