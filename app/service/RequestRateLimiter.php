<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use think\facade\Log;
use Throwable;

final class RequestRateLimiter
{
    public function __construct(private readonly FileRateLimitStore $files)
    {
    }

    public function attempts(string $key): int
    {
        $fileAttempts = $this->files->attempts($key);
        try {
            return max($fileAttempts, (int) Cache::get($key, 0));
        } catch (Throwable $exception) {
            $this->logFallback($exception);
            return $fileAttempts;
        }
    }

    public function hit(string $key, int $ttl): int
    {
        $fileAttempts = $this->files->hit($key, $ttl);
        try {
            $attempts = max($fileAttempts, (int) Cache::get($key, 0) + 1);
            Cache::set($key, $attempts, max(1, $ttl));
            return $attempts;
        } catch (Throwable $exception) {
            $this->logFallback($exception);
            return $fileAttempts;
        }
    }

    public function clear(string $key): void
    {
        try {
            Cache::delete($key);
        } catch (Throwable $exception) {
            $this->logFallback($exception);
        }
        $this->files->clear($key);
    }

    private function logFallback(Throwable $exception): void
    {
        Log::warning('缓存限流不可用，已切换到本地持久限流', ['exception' => $exception->getMessage()]);
    }
}
