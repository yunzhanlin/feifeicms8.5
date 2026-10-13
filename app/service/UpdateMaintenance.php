<?php
declare(strict_types=1);
namespace app\service;

/** Native-PHP barrier: used before Composer/framework code is loaded. */
final class UpdateMaintenance
{
    /** @return resource Holds a shared lease until the HTTP request completes. */
    public static function enter(string $root)
    {
        $directory = rtrim($root, '/\\') . '/runtime/updates';
        $lock = self::open($directory);
        if (is_file($directory . '/maintenance.json') || !flock($lock, LOCK_SH | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('程序正在更新或等待修复，请稍后重试');
        }
        try {
            clearstatcache(true, $directory . '/maintenance.json');
            if (is_file($directory . '/maintenance.json')) throw new \RuntimeException('程序正在更新，请稍后重试');
            self::refreshOpcache($root, $directory);
            return $lock;
        } catch (\Throwable $error) { self::leave($lock); throw $error; }
    }

    /** @return resource Prevent new requests, then drain all in-flight readers. */
    public static function begin(string $root, string $job, float $timeout = 30.0)
    {
        $directory = rtrim($root, '/\\') . '/runtime/updates';
        $lock = self::open($directory);
        if (is_file($directory . '/maintenance.json')) { fclose($lock); throw new \RuntimeException('上次更新仍处于维护状态，请先修复后解除维护'); }
        self::write($directory . '/maintenance.json', json_encode(['job' => $job, 'started_at' => gmdate('c')], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + $timeout;
        do {
            if (flock($lock, LOCK_EX | LOCK_NB)) return $lock;
            usleep(20_000);
        } while (microtime(true) < $deadline);
        unlink($directory . '/maintenance.json');
        fclose($lock);
        throw new \RuntimeException('已有请求未结束，更新未开始；请稍后重试');
    }

    /** Writer must still own the exclusive lease. */
    public static function finish(string $root, $lock, bool $consistent): void
    {
        $directory = rtrim($root, '/\\') . '/runtime/updates';
        try {
            // FPM handles invalidate their own opcode cache BEFORE autoloading.
            // CLI opcache_reset() alone is not a web-runtime invalidation.
            self::write($directory . '/generation', bin2hex(random_bytes(16)));
            if ($consistent && is_file($directory . '/maintenance.json') && !unlink($directory . '/maintenance.json')) throw new \RuntimeException('更新完成，但维护标记无法移除');
        } finally { self::leave($lock); }
    }

    public static function leave($lock): void
    {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** @return resource */
    private static function open(string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('无法建立更新维护目录');
        $lock = fopen($directory . '/maintenance.lock', 'c');
        if ($lock === false) throw new \RuntimeException('无法打开更新维护锁');
        return $lock;
    }

    private static function refreshOpcache(string $root, string $directory): void
    {
        if (!function_exists('opcache_get_status')) return;
        $status = opcache_get_status(true);
        if (!is_array($status) || empty($status['opcache_enabled']) || !is_file($directory . '/generation')) return;
        $generation = trim((string) file_get_contents($directory . '/generation'));
        if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) throw new \RuntimeException('更新缓存标记无效');
        $receipt = $directory . '/opcache-' . PHP_SAPI . '-' . getmypid();
        if (is_file($receipt) && trim((string) file_get_contents($receipt)) === $generation) return;
        $prefix = rtrim((string) realpath($root), '/\\') . DIRECTORY_SEPARATOR;
        foreach (array_keys($status['scripts'] ?? []) as $file) {
            if (!str_starts_with($file, $prefix)) continue;
            // A completed install/update may deliberately remove an entry.
            // A stale opcode entry for a missing file is not a refresh failure.
            if (!is_file($file)) { @opcache_invalidate($file, true); continue; }
            if (!opcache_invalidate($file, true)) throw new \RuntimeException('PHP 缓存刷新失败，请重载 PHP-FPM 后重试');
        }
        self::write($receipt, $generation);
    }

    private static function write(string $path, string $value): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $value, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) throw new \RuntimeException('无法保存更新维护状态');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
