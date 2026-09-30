<?php
declare(strict_types=1);

namespace app\service;

final class FileRateLimitStore
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? runtime_path() . 'rate-limits';
    }

    public function attempts(string $key): int
    {
        return $this->withLock($key, static function (array $state): array {
            return [$state, (int) ($state['attempts'] ?? 0)];
        });
    }

    public function hit(string $key, int $ttl): int
    {
        return $this->withLock($key, static function (array $state) use ($ttl): array {
            $attempts = (int) ($state['attempts'] ?? 0) + 1;
            return [['attempts' => $attempts, 'expires' => time() + max(1, $ttl)], $attempts];
        });
    }

    public function clear(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) @unlink($path);
    }

    /**
     * @template T
     * @param callable(array{attempts?:int,expires?:int}):array{array{attempts?:int,expires?:int},T} $callback
     * @return T
     */
    private function withLock(string $key, callable $callback): mixed
    {
        $this->ensureDirectory();
        $handle = fopen($this->path($key), 'c+');
        if ($handle === false) throw new \RuntimeException('无法打开限流状态文件');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('无法锁定限流状态文件');
            rewind($handle);
            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($state) || (int) ($state['expires'] ?? 0) <= time()) $state = [];
            [$next, $result] = $callback($state);
            ftruncate($handle, 0);
            rewind($handle);
            if ($next !== []) {
                fwrite($handle, json_encode($next, JSON_THROW_ON_ERROR));
                fflush($handle);
            }
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('无法创建限流状态目录');
        }
        @chmod($this->directory, 0700);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
