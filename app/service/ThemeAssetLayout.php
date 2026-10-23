<?php
declare(strict_types=1);

namespace app\service;

/** One-time, database-free bridge for packages accepted by older updaters. */
final class ThemeAssetLayout
{
    public static function publish(string $directory): int
    {
        $root = realpath($directory);
        if ($root === false) throw new \RuntimeException('程序目录不存在');
        if (is_link($root . '/view')) throw new \RuntimeException('模板目录不允许软链接');
        $sources = [];
        foreach (glob($root . '/view/*', GLOB_ONLYDIR) ?: [] as $theme) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/D', basename($theme)) || is_link($theme)) continue;
            if (is_dir($theme . '/assets') || is_link($theme . '/assets')) $sources[basename($theme)] = $theme . '/assets';
        }
        if ($sources === []) return 0;

        $state = self::directory($root, 'runtime/theme-asset-layout', 0700);
        if (is_link($state . '/publish.lock')) throw new \RuntimeException('资源迁移锁无效');
        $lock = fopen($state . '/publish.lock', 'c');
        if ($lock === false) throw new \RuntimeException('无法创建资源迁移锁');
        $count = 0;
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('无法锁定资源迁移');
            self::directory($root, 'public/theme-assets', 0755);
            foreach ($sources as $theme => $source) {
                clearstatcache(true, $source);
                if (!is_dir($source) && !is_link($source)) continue;
                if (is_link($source)) throw new \RuntimeException('旧资源目录不允许软链接：' . $theme);
                $backupRelative = 'runtime/retired-theme-assets/' . $theme . '-' . bin2hex(random_bytes(8));
                $backup = self::directory($root, $backupRelative, 0700);
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if (!$file->isFile() || $file->isLink()) continue;
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
                    // Never publish source, hidden files or links from a legacy theme.
                    if (!ThemeAssets::isAllowedPath($relative)) continue;
                    $target = $root . '/public/theme-assets/' . $theme . '/' . $relative;
                    self::directory($root, dirname('public/theme-assets/' . $theme . '/' . $relative), 0755);
                    if (is_link($target) || (file_exists($target) && !is_file($target))) throw new \RuntimeException('目标资源路径无效：' . $relative);
                    if (is_file($target)) {
                        self::directory($root, $backupRelative . '/previous-public/' . dirname($relative), 0700);
                        if (!copy($target, $backup . '/previous-public/' . $relative)) throw new \RuntimeException('无法备份原公开资源');
                    }
                    $temporary = tempnam(dirname($target), '.theme-asset-');
                    if ($temporary === false) throw new \RuntimeException('无法暂存主题资源');
                    try {
                        $hash = hash_file('sha256', $file->getPathname());
                        if ($hash === false || !copy($file->getPathname(), $temporary) || !chmod($temporary, 0644)
                            || !hash_equals($hash, (string) hash_file('sha256', $temporary)) || !rename($temporary, $target)) {
                            throw new \RuntimeException('主题资源迁移失败：' . $relative);
                        }
                        ++$count;
                    } finally { if (is_file($temporary)) unlink($temporary); }
                }
                // Keep all old files (including skipped private files) recoverable.
                if (!rename($source, $backup . '/previous-private-assets')) throw new \RuntimeException('资源已复制，但旧目录无法备份，请检查 view/' . $theme . ' 权限');
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return $count;
    }

    private static function directory(string $root, string $relative, int $mode): string
    {
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '.' || $part === '') continue;
            if ($part === '..') throw new \RuntimeException('资源目录越界');
            $path .= '/' . $part;
            if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new \RuntimeException('资源目录包含软链接或非目录项');
            if (!is_dir($path) && !mkdir($path, $mode) && !is_dir($path)) throw new \RuntimeException('无法创建主题资源目录');
        }
        return $path;
    }
}
