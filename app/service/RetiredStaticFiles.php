<?php
declare(strict_types=1);

namespace app\service;

/** Retire legacy exports on the first new-code boot, including old updater runs. */
final class RetiredStaticFiles
{
    public static function retire(string $directory): void
    {
        $root = realpath($directory);
        if ($root === false) throw new \RuntimeException('程序目录不存在');
        $targets = ['public/generated', 'view/admin/tools/static.html', 'view/admin/tools/static.tpl'];
        $exists = static fn (string $path): bool => file_exists($path) || is_link($path);
        if (!array_filter($targets, static fn (string $path): bool => $exists($root . '/' . $path))) return;

        $backup = $root;
        foreach (['runtime', 'retired-static'] as $part) {
            $backup .= '/' . $part;
            if (is_link($backup) || (file_exists($backup) && !is_dir($backup))) throw new \RuntimeException('静态副本隔离目录无效');
            if (!is_dir($backup) && !@mkdir($backup, 0700) && !is_dir($backup)) throw new \RuntimeException('无法创建静态副本隔离目录');
        }
        $lockPath = $backup . '/retire.lock';
        if (is_link($lockPath)) throw new \RuntimeException('静态副本隔离锁无效');
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('无法锁定旧静态副本');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('无法锁定旧静态副本');
            foreach ($targets as $relative) {
                $source = $root . '/' . $relative;
                clearstatcache(true, $source);
                if (!$exists($source)) continue;
                for ($parent = dirname($source); $parent !== $root; $parent = dirname($parent)) {
                    if (is_link($parent)) throw new \RuntimeException('旧静态副本路径包含软链接');
                }
                // Rename the exact target, never traverse or delete its contents.
                // A source symlink is moved as a link; its destination is untouched.
                $target = $backup . '/' . str_replace('/', '-', $relative) . '-' . bin2hex(random_bytes(8));
                if (!@rename($source, $target) && $relative === 'public/generated') {
                    throw new \RuntimeException('无法隔离旧静态副本，请先由管理员移走 public/generated 并更新 Web 服务器规则');
                }
                // A private, unreachable old template may be read-only after a
                // manual overwrite deployment. It must not take the site down.
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
