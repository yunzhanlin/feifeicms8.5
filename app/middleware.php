<?php
// 全局中间件定义文件
// Also works with an older authoritative Composer map during an in-place upgrade.
require_once __DIR__ . '/service/HtmlMinifier.php';
require_once __DIR__ . '/middleware/FrontendHtml.php';
return [
    app\middleware\SecurityHeaders::class,
    app\middleware\PrivateFiles::class,
    app\middleware\SiteAvailability::class,
    app\middleware\FrontendHtml::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    // \think\middleware\SessionInit::class
];
