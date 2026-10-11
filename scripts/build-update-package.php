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
$roots = ['app', 'config', 'database/migrations', 'extend', 'route', 'view/admin', 'view/install', 'view/mxone', 'vendor',
    'public/static', 'public/legacy', 'public/mxstatic', 'public/player'];
$single = ['think', '.htaccess', 'composer.json', 'composer.lock', 'public/index.php', 'public/install.php',
    'public/router.php', 'public/.htaccess', 'public/favicon.ico', 'public/robots.txt'];
if ($mode === 'install') {
    $roots = array_merge($roots, ['deploy', 'docker', 'docs', 'plugins/legacy43']);
    $single = array_merge($single, ['database/schema-v2.sql', '.example.env', 'README.md', 'LICENSE.txt', 'NOTICE', 'Dockerfile', 'docker-compose.yml']);
}
$files = [];
foreach ($roots as $directory) {
    if (!is_dir($root . '/' . $directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(?:^|/)(?:\.DS_Store|Thumbs\.db|\.env(?:\.[^/]+)?)$~i', $relative)) continue;
        if (str_starts_with($relative, 'view/') && strtolower($file->getExtension()) !== 'html') continue;
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
    }
}
foreach ($single as $relative) {
    if (is_file($root . '/' . $relative) && !is_link($root . '/' . $relative)) $files[$relative] = hash_file('sha256', $root . '/' . $relative);
}
ksort($files, SORT_STRING);
if (!isset($files['vendor/autoload.php'], $files['config/version.php'], $files['app/service/ReleaseCatalog.php'])) throw new RuntimeException('构建缺少程序或 Composer 依赖');
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建更新包');
if ($mode === 'install') {
    $zip->addEmptyDir('runtime');
    $zip->addEmptyDir('public/uploads');
}
foreach ($files as $relative => $_) if (!$zip->addFile($root . '/' . $relative, $relative)) throw new RuntimeException('无法加入文件：' . $relative);
$manifest = ['format' => 1, 'version' => $release['version'], 'files' => $files];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if (!$zip->close()) throw new RuntimeException('无法完成更新包');
file_put_contents($target . '.sha256', hash_file('sha256', $target) . '  ' . basename($target) . "\n", LOCK_EX);
echo sprintf("[OK] %s: %d files, %d bytes\n", $target, count($files), filesize($target));
