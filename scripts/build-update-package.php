<?php
declare(strict_types=1);

/** Build only program files; secrets, uploads, runtime data and tests stay out. */
$root = dirname(__DIR__);
$release = require $root . '/config/version.php';
$tag = (string) ($argv[1] ?? '');
if ($tag !== 'v' . $release['version']) throw new RuntimeException('发布标签与 config/version.php 不一致');
$target = (string) ($argv[2] ?? $root . '/feifeicms-update.zip');
$roots = ['app', 'config', 'database/migrations', 'extend', 'plugins', 'route', 'view', 'vendor',
    'public/static', 'public/legacy', 'public/mxstatic', 'public/player'];
$single = ['think', 'composer.json', 'composer.lock', 'public/index.php', 'public/install.php',
    'public/router.php', 'public/.htaccess', 'public/favicon.ico', 'public/robots.txt'];
$files = [];
foreach ($roots as $directory) {
    if (!is_dir($root . '/' . $directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
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
foreach ($files as $relative => $_) if (!$zip->addFile($root . '/' . $relative, $relative)) throw new RuntimeException('无法加入文件：' . $relative);
$manifest = ['format' => 1, 'version' => $release['version'], 'files' => $files];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if (!$zip->close()) throw new RuntimeException('无法完成更新包');
file_put_contents($target . '.sha256', hash_file('sha256', $target) . "  feifeicms-update.zip\n", LOCK_EX);
echo sprintf("[OK] %s: %d files, %d bytes\n", $target, count($files), filesize($target));
