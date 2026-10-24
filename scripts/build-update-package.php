<?php
declare(strict_types=1);

/** Build only program files; secrets, uploads, runtime data and tests stay out. */
$root = dirname(__DIR__);
$release = require $root . '/config/version.php';
$tag = (string) ($argv[1] ?? '');
if ($tag !== 'v' . $release['version']) throw new RuntimeException('发布标签与 config/version.php 不一致');
$target = (string) ($argv[2] ?? $root . '/feifeicms-update.zip');
$mode = (string) ($argv[3] ?? 'update');
if (!in_array($mode, ['update', 'patch', 'install'], true)) throw new RuntimeException('无效构建类型');
$updating = $mode !== 'install';
$base = null;
if ($updating) {
    $baseline = (string) ($argv[4] ?? '');
    $baseSpec = json_decode((string) file_get_contents($root . '/config/release-base.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_file($baseline) || !hash_equals((string) $baseSpec['sha256'], hash_file('sha256', $baseline))) {
        throw new RuntimeException('更新包必须提供与 release-base.json 校验值一致的上版发布包');
    }
    $baseZip = new ZipArchive();
    if ($baseZip->open($baseline) !== true) throw new RuntimeException('无法读取基线发布包');
    try {
        $base = json_decode((string) $baseZip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($base['format'] ?? null) !== 1 || ($base['version'] ?? '') !== $baseSpec['version'] || !is_array($base['files'] ?? null)) {
            throw new RuntimeException('更新基线清单无效');
        }
    } finally { $baseZip->close(); }
}
$installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
if (!is_array($installed) || ($installed['dev'] ?? null) !== false || ($installed['dev-package-names'] ?? []) !== []) {
    throw new RuntimeException('发布包必须先执行 composer install --no-dev --classmap-authoritative，不能打包开发依赖');
}
$roots = ['app', 'config', 'database/migrations', 'extend', 'route', 'view/admin', 'view/install', 'view/mxone', 'view/shared',
    'public/theme-assets/admin', 'public/theme-assets/install', 'public/theme-assets/mxone', 'public/theme-assets/shared', 'vendor'];
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
        // Site-owned templates, static assets and configuration never enter updates.
        if ($updating && (str_starts_with($relative, 'view/') || str_starts_with($relative, 'public/')
            || (str_starts_with($relative, 'config/') && $relative !== 'config/version.php'))) continue;
        if ($relative === 'config/release-base.json') continue;
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
    if ($updating && !in_array($relative, ['think', 'composer.json', 'composer.lock', 'public/index.php', 'public/router.php'], true)) continue;
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
foreach ($updating ? [] : ['public/theme-assets/admin/css/admin.css', 'public/theme-assets/admin/js/admin.js', 'public/theme-assets/install/css/install.css',
    'view/mxone/common/head.tpl', 'public/theme-assets/mxone/css/system.css', 'public/theme-assets/mxone/js/app.js',
    'view/mxone/extensions/functions.php', 'view/shared/extensions/tags.php', 'public/theme-assets/shared/player/player.js',
    'public/theme-assets/shared/player/vendor/artplayer.js', 'public/theme-assets/shared/player/vendor/hls.min.js',
    'public/theme-assets/shared/player/vendor/artplayer-plugin-danmuku.js'] as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少主题运行文件：' . $required);
}
foreach ($updating ? [] : array_merge(['vendor/overtrue/pinyin/src/Pinyin.php', 'vendor/overtrue/pinyin/data/chars.php',
    'vendor/overtrue/pinyin/data/surnames.php'], array_map(static fn (int $part): string => 'vendor/overtrue/pinyin/data/words-' . $part . '.php', range(0, 9))) as $required) {
    if (!isset($files[$required])) throw new RuntimeException('构建缺少拼音运行文件：' . $required);
}
if ($updating) {
    $allFiles = $files;
    $files = array_filter($files, static fn (string $hash, string $path): bool => ($base['files'][$path] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH);
    if (!isset($files['config/version.php'])) throw new RuntimeException('增量包必须包含新版本标识');
    if ($mode === 'update') {
        // The released unpacker requires these files and at least ten entries.
        foreach (['app/service/ProgramUpdater.php', 'vendor/autoload.php'] as $path) $files[$path] = $allFiles[$path];
        foreach (['app/service/ReleaseCatalog.php', 'app/service/UpdateMaintenance.php', 'app/service/PhpCliLauncher.php',
            'app/service/ResilientCache.php', 'think', 'public/index.php'] as $path) {
            if (count($files) >= 10) break;
            $files[$path] = $allFiles[$path];
        }
        if (count($files) < 10) throw new RuntimeException('在线兼容包缺少旧更新器要求的核心文件');
    }
    // Old unpackers already require this identity before replacing any files.
    // Validate the base here as well; they do not understand base_version yet.
    $guard = "\n// Check the installed base while an old updater validates its staging directory.\n"
        . 'if (function_exists(\'root_path\')) {' . "\n"
        . '    $installedFile = root_path() . \'config/version.php\';' . "\n"
        . '    if (is_file($installedFile) && realpath($installedFile) !== realpath(__FILE__)) {' . "\n"
        . '        $installedVersion = (require $installedFile)[\'version\'] ?? \'\';' . "\n"
        . '        if ($installedVersion !== ' . var_export($base['version'], true) . ') {' . "\n"
        . '            throw new \\RuntimeException(' . var_export('本更新包仅适用于 ' . $base['version'] . '；请先按发布说明升级到该版本。', true) . ');' . "\n"
        . "        }\n    }\n}\n";
    $identity = (string) file_get_contents($sources['config/version.php']);
    $contents['config/version.php'] = str_replace('return [', $guard . "\nreturn [", $identity);
    $files['config/version.php'] = hash('sha256', $contents['config/version.php']);
    foreach (array_keys($files) as $path) {
        if (str_starts_with($path, 'view/') || (str_starts_with($path, 'public/') && !in_array($path, ['public/index.php', 'public/router.php'], true))
            || (str_starts_with($path, 'config/') && $path !== 'config/version.php')
            || preg_match('~\.(?:tpl|html?|css|js|map|json|ya?ml|ini|env|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|ico)$~i', $path) && !str_starts_with($path, 'vendor/') && $path !== 'composer.json') {
            throw new RuntimeException('更新包含模板、样式或网站配置：' . $path);
        }
    }
    ksort($files, SORT_STRING);
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
if ($updating) {
    $manifest['package_type'] = $mode === 'patch' ? 'delta' : 'online-compatible';
    $manifest['base_version'] = $base['version'];
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if (!$zip->close()) throw new RuntimeException('无法完成更新包');
file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n", LOCK_EX);
echo sprintf("[OK] %s: %d files, %d bytes\n", $target, count($files), filesize($target));
