<?php
declare(strict_types=1);

// Avoid artificial deltas caused only by Composer's regenerated metadata.
// Reuse a verified production vendor tree only when dependencies and class maps agree.
$root = dirname(__DIR__);
$archive = (string) ($argv[1] ?? '');
$spec = json_decode((string) file_get_contents($root . '/config/release-base.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_file($archive) || !hash_equals($spec['sha256'], hash_file('sha256', $archive))) throw new RuntimeException('依赖基线校验失败');
$zip = new ZipArchive();
if ($zip->open($archive) !== true) throw new RuntimeException('无法读取依赖基线');
$temporary = $root . '/runtime/dependency-baseline-' . bin2hex(random_bytes(8));
$remove = static function (string $path): void {
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isDir()) unlink($file->getPathname());
        else rmdir($file->getPathname());
    }
    rmdir($path);
};
try {
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!isset($manifest['files']['composer.lock'])) {
        echo "[OK] 基线是增量包，不覆盖 Composer 生成的生产依赖\n";
        return;
    }
    if (($manifest['files']['composer.lock'] ?? '') !== hash_file('sha256', $root . '/composer.lock')) {
        echo "[OK] 依赖锁文件已变化，保留 Composer 新生成的生产依赖\n";
        return;
    }
    mkdir($temporary, 0700, true);
    foreach ($manifest['files'] as $path => $hash) {
        if (!str_starts_with($path, 'vendor/')) continue;
        if (str_contains($path, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) throw new RuntimeException('依赖基线路径非法');
        $content = $zip->getFromName($path);
        if (!is_string($content) || !hash_equals($hash, hash('sha256', $content))) throw new RuntimeException('依赖基线文件校验失败');
        if (!is_dir(dirname($temporary . '/' . $path))) mkdir(dirname($temporary . '/' . $path), 0700, true);
        file_put_contents($temporary . '/' . $path, $content);
    }
    $normalize = static function (string $directory): array {
        $map = require $directory . '/vendor/composer/autoload_classmap.php';
        foreach ($map as &$path) $path = str_replace($directory . '/', '', $path);
        unset($path); ksort($map); return $map;
    };
    if ($normalize($root) !== $normalize($temporary)) {
        echo "[OK] 程序类映射已变化，保留 Composer 新生成的生产依赖\n";
        return;
    }
    if (is_link($root . '/vendor')) throw new RuntimeException('发布依赖目录不能是软链接');
    $remove($root . '/vendor');
    if (!rename($temporary . '/vendor', $root . '/vendor')) throw new RuntimeException('无法保留已验证的生产依赖');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/vendor', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) chmod($file->getPathname(), $file->isDir() ? 0755 : 0644);
    chmod($root . '/vendor', 0755);
    echo "[OK] 生产依赖与程序类映射相同，复用已发布文件以保持差量最小\n";
} finally {
    $zip->close();
    $remove($temporary);
}
