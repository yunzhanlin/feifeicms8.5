<?php
declare(strict_types=1);

/** Build only program files; secrets, uploads, runtime data and tests stay out. */
$root = dirname(__DIR__);
$release = require $root . '/config/version.php';
$tag = (string) ($argv[1] ?? '');
if ($tag !== 'v' . $release['version']) throw new RuntimeException('发布标签与 config/version.php 不一致');
$target = (string) ($argv[2] ?? $root . '/feifeicms-update.zip');
$mode = (string) ($argv[3] ?? 'update');
if (!in_array($mode, ['update', 'install'], true)) throw new RuntimeException('无效构建类型');
$installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
if (!is_array($installed) || ($installed['dev'] ?? null) !== false || ($installed['dev-package-names'] ?? []) !== []) {
    throw new RuntimeException('发布包必须先执行 composer install --no-dev --classmap-authoritative，不能打包开发依赖');
}
$roots = ['app', 'config', 'database/migrations', 'extend', 'route', 'view/admin', 'view/install', 'view/mxone', 'view/shared', 'vendor'];
$single = ['think', '.htaccess', 'composer.json', 'composer.lock', 'public/index.php',
    'public/router.php', 'public/.htaccess', 'public/favicon.ico', 'public/robots.txt'];
if ($mode === 'install') {
    // Native web installation only: optional deployment and migration add-ons
    // remain in the repository, not in a new site's program directory.
    $single = array_merge($single, ['public/install.php', 'database/schema-v2.sql',
        'deploy/baota/thinkphp.conf', 'deploy/apache/feifeicms.conf', 'docs/模板标签.md', 'LICENSE.txt', 'NOTICE']);
}
$files = [];
$sources = [];
$contents = [];
foreach ($roots as $directory) {
    if (!is_dir($root . '/' . $directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(?:^|/)(?:\.DS_Store|Thumbs\.db|\.env(?:\.[^/]+)?)$~i', $relative)) continue;
        if (str_starts_with($relative, 'view/') && preg_match('~(?:^|/)(?:tests?|docs?|examples?|\.git)(?:/|$)~i', $relative)) continue;
        if (str_starts_with($relative, 'view/')) {
            $extension = strtolower($file->getExtension());
            if (str_contains($relative, '/extensions/')) {
                if ($extension !== 'php') continue;
            } elseif (str_contains($relative, '/assets/')) {
                if (!in_array($extension, ['css','js','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','eot'], true)) continue;
            } elseif ($extension !== 'tpl') continue;
        }
        // Local-only theme assets must not leak into an otherwise shared asset root.
        if (preg_match('~(?:^|/)(?:qinxin|qingxin)(?:[./_-]|$)~i', $relative)) continue;
        if (str_starts_with($relative, 'vendor/')) {
            if (preg_match('~/(?:tests?|docs?|examples?|benchmarks?|\.github)(?:/|$)~i', $relative)) continue;
            if (preg_match('~/(?:phpunit|phpstan|phpcs|psalm|rector)(?:[.-]|$)|/(?:\.travis\.yml|\.gitignore|\.gitattributes|\.editorconfig)$~i', $relative)) continue;
            // Preserve dependency licenses/notices, not upstream developer prose.
            if (in_array(strtolower($file->getExtension()), ['md', 'rst'], true)
                && !preg_match('~/(?:licen[cs]e|copying|notice)(?:[.-]|$)~i', $relative)) continue;
        }
        $files[$relative] = hash_file('sha256', $file->getPathname());
        $sources[$relative] = $file->getPathname();
    }
}
foreach ($single as $relative) {
    if (is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)) {
        $files[$relative] = hash_file('sha256', $root . '/' . $relative);
        $sources[$relative] = $root . '/' . $relative;
    }
}
if ($mode === 'install') {
    $guide = $root . '/docs/安装说明.md';
    if (!is_file($guide) || is_link($guide)) throw new RuntimeException('完整安装包缺少安装说明');
    $contents['README.md'] = str_replace(['(../deploy/', '(模板标签.md)'],
        ['(deploy/', '(docs/模板标签.md)'], (string) file_get_contents($guide));
    $files['README.md'] = hash('sha256', $contents['README.md']);
    foreach (['public/install.php', 'database/schema-v2.sql', 'deploy/baota/thinkphp.conf', 'docs/模板标签.md'] as $required) {
        if (!isset($files[$required])) throw new RuntimeException('完整安装包缺少文件：' . $required);
    }
}
ksort($files, SORT_STRING);
if (!isset($files['vendor/autoload.php'], $files['config/version.php'], $files['app/service/ReleaseCatalog.php'])) throw new RuntimeException('构建缺少程序或 Composer 依赖');
foreach (['view/admin/assets/css/admin.css', 'view/admin/assets/js/admin.js', 'view/install/assets/css/install.css',
    'view/mxone/common/head.tpl', 'view/mxone/assets/css/system.css', 'view/mxone/assets/js/app.js',
    'view/mxone/extensions/functions.php', 'view/shared/extensions/tags.php', 'view/shared/assets/player/player.js',
    'view/shared/assets/player/vendor/artplayer.js', 'view/shared/assets/player/vendor/hls.min.js',
    'view/shared/assets/player/vendor/artplayer-plugin-danmuku.js'] as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少主题运行文件：' . $required);
}
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建更新包');
if ($mode === 'install') {
    $zip->addEmptyDir('runtime');
    $zip->addEmptyDir('public/uploads');
}
foreach ($files as $relative => $_) {
    $added = isset($contents[$relative]) ? $zip->addFromString($relative, $contents[$relative]) : $zip->addFile($sources[$relative], $relative);
    if (!$added) throw new RuntimeException('无法加入文件：' . $relative);
}
$manifest = ['format' => 1, 'version' => $release['version'], 'files' => $files];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if (!$zip->close()) throw new RuntimeException('无法完成更新包');
file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n", LOCK_EX);
echo sprintf("[OK] %s: %d files, %d bytes\n", $target, count($files), filesize($target));
