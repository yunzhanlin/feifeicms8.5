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
$updating = $mode === 'update';
$installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
if (!is_array($installed) || ($installed['dev'] ?? null) !== false || ($installed['dev-package-names'] ?? []) !== []) {
    throw new RuntimeException('发布包必须先执行 composer install --no-dev --classmap-authoritative，不能打包开发依赖');
}
// The oldest supported site (8.5.261023.2) ships this dependency lock and
// installs schema 17. A new lock requires the matching runtime dependencies.
$compatibleLock = '4d31f207c7ba60f746e70ccbf631b4b536a6e2967ec399342d989337669d1015';
$reuseDependencies = $updating && hash_file('sha256', $root . '/composer.lock') === $compatibleLock;
$compatibleSchema = 17;
$roots = ['app', 'database/migrations', 'extend', 'route', 'vendor'];
$single = ['think', 'composer.json', 'composer.lock', 'config/version.php', 'public/index.php', 'public/router.php'];
if (!$updating) {
    // An install also needs its default settings, templates and public assets.
    $roots = array_merge($roots, ['config', 'view/admin', 'view/install', 'view/mxone', 'view/shared',
        'public/theme-assets/admin', 'public/theme-assets/install', 'public/theme-assets/mxone', 'public/theme-assets/shared']);
    $single = array_merge($single, ['.htaccess', 'public/.htaccess', 'public/favicon.ico', 'public/robots.txt',
        'public/install.php', 'database/schema-v2.sql', 'deploy/baota/thinkphp.conf',
        'deploy/apache/feifeicms.conf', 'LICENSE.txt', 'NOTICE']);
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
        if (preg_match('~(?:^|/)docs?(?:/|$)~i', $relative)) continue;
        if (preg_match('~(?:^|/)(?:\.DS_Store|Thumbs\.db|\.env(?:\.[^/]+)?)$~i', $relative)) continue;
        if (str_starts_with($relative, 'view/') && preg_match('~(?:^|/)(?:tests?|docs?|examples?|\.git)(?:/|$)~i', $relative)) continue;
        if (str_starts_with($relative, 'view/')) {
            $extension = strtolower($file->getExtension());
            if (str_contains($relative, '/extensions/')) {
                if ($extension !== 'php') continue;
            } elseif (str_contains($relative, '/assets/')) continue;
            elseif ($extension !== 'tpl') continue;
        }
        if (str_starts_with($relative, 'public/theme-assets/')) {
            $asset = explode('/', substr($relative, strlen('public/theme-assets/')), 2)[1] ?? '';
            if (!preg_match('~^[\pL\pN_@-][\pL\pN_.@-]*(?:/[\pL\pN_@-][\pL\pN_.@-]*)*$~uD', $asset)
                || preg_match('~\.(?:php[0-9]*|phtml|phar|tpl|html?|ini|env)(?:[./]|$)~i', $asset)
                || !in_array(strtolower($file->getExtension()), ['css','js','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','eot'], true)) {
                throw new RuntimeException('公开主题目录只能包含静态资源：' . $relative);
            }
        }
        // Local-only theme assets must not leak into an otherwise shared asset root.
        if (preg_match('~(?:^|/)(?:qinxin|qingxin)(?:[./_-]|$)~i', $relative)) continue;
        if ($updating && preg_match('~^database/migrations/(\d{3})_.*\.sql$~', $relative, $migration)
            && (int) $migration[1] <= $compatibleSchema) continue;
        if (str_starts_with($relative, 'vendor/')) {
            // Authoritative class maps must be refreshed even when libraries are reused.
            if ($reuseDependencies && !str_starts_with($relative, 'vendor/composer/')
                && !in_array($relative, ['vendor/autoload.php', 'vendor/services.php'], true)) continue;
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
    if (preg_match('~(?:^|/)docs?(?:/|$)~i', $relative)) continue;
    if (is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)) {
        $files[$relative] = hash_file('sha256', $root . '/' . $relative);
        $sources[$relative] = $root . '/' . $relative;
    }
}
if (!$updating) {
    $guide = $root . '/docs/安装说明.md';
    if (!is_file($guide) || is_link($guide)) throw new RuntimeException('完整安装包缺少安装说明');
    $onlineDocs = 'https://github.com/yunzhanlin/feifeicms8.5/blob/main/docs/';
    $contents['README.md'] = str_replace(['(../deploy/', '(模板标签.md)'],
        ['(deploy/', '(' . $onlineDocs . '模板标签.md)'], (string) file_get_contents($guide));
    $files['README.md'] = hash('sha256', $contents['README.md']);
    foreach (['public/install.php', 'database/schema-v2.sql', 'deploy/baota/thinkphp.conf'] as $required) {
        if (!isset($files[$required])) throw new RuntimeException('完整安装包缺少文件：' . $required);
    }
}
ksort($files, SORT_STRING);
foreach (['think', 'config/version.php', 'public/index.php', 'public/router.php', 'app/service/ProgramUpdater.php',
    'app/service/ReleaseCatalog.php', 'app/admin_views/drama_metadata.tpl', 'app/admin_views/drama_metadata.js',
    'vendor/autoload.php', 'vendor/composer/autoload_classmap.php', 'vendor/composer/autoload_static.php'] as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少程序或 Composer 依赖：' . $required);
}
foreach ($updating ? [] : ['public/theme-assets/admin/css/admin.css', 'public/theme-assets/admin/js/admin.js', 'public/theme-assets/install/css/install.css',
    'view/mxone/common/head.tpl', 'public/theme-assets/mxone/css/system.css', 'public/theme-assets/mxone/js/app.js',
    'view/mxone/extensions/functions.php', 'view/shared/extensions/tags.php', 'public/theme-assets/shared/player/player.js',
    'public/theme-assets/shared/player/vendor/artplayer.js', 'public/theme-assets/shared/player/vendor/hls.min.js',
    'public/theme-assets/shared/player/vendor/artplayer-plugin-danmuku.js'] as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少主题运行文件：' . $required);
}
foreach ($reuseDependencies ? [] : array_merge(['vendor/overtrue/pinyin/src/Pinyin.php', 'vendor/overtrue/pinyin/data/chars.php',
    'vendor/overtrue/pinyin/data/surnames.php'], array_map(static fn (int $part): string => 'vendor/overtrue/pinyin/data/words-' . $part . '.php', range(0, 9))) as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少拼音运行文件：' . $required);
}
if ($updating) {
    // Keep site-owned templates and assets, while shipping the built-in collection page with its PHP controllers.
    foreach (array_keys($files) as $path) {
        if (str_starts_with($path, 'view/') || (str_starts_with($path, 'public/') && !in_array($path, ['public/index.php', 'public/router.php'], true))
            || (str_starts_with($path, 'config/') && $path !== 'config/version.php')
            || preg_match('~\.(?:tpl|html?|css|js|map|json|ya?ml|ini|env|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)$~i', $path)
                && !str_starts_with($path, 'vendor/') && !in_array($path, ['composer.json', 'app/admin_views/drama_metadata.tpl', 'app/admin_views/drama_metadata.js'], true)) {
            throw new RuntimeException('更新包含模板、样式或网站配置：' . $path);
        }
    }
}
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建更新包');
if (!$updating) {
    $zip->addEmptyDir('runtime');
    $zip->addEmptyDir('public/uploads');
}
foreach ($files as $relative => $_) {
    $added = isset($contents[$relative]) ? $zip->addFromString($relative, $contents[$relative]) : $zip->addFile($sources[$relative], $relative);
    if (!$added) throw new RuntimeException('无法加入文件：' . $relative);
}
$manifest = ['format' => 1, 'version' => $release['version'], 'files' => $files];
if ($updating) {
    $manifest['package_type'] = 'full-update';
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if (!$zip->close()) throw new RuntimeException('无法完成更新包');
file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n", LOCK_EX);
echo sprintf("[OK] %s: %d files, %d bytes\n", $target, count($files), filesize($target));
