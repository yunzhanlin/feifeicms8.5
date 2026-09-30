<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use think\facade\Cache;
use Throwable;

final class ResilientCache
{
    /** @var array<string, array{value:mixed,expires:int}> */
    private static array $fallback = [];
    /** @var array<string, array{value:mixed,expires:int}> Request-local hot values avoid duplicate Redis round trips. */
    private static array $memo = [];

    public function get(string $key, mixed $default = null): mixed
    {
        try { return Cache::get($key, $default); } catch (Throwable) {
            $item = self::$fallback[$key] ?? null;
            if ($item === null || $item['expires'] < time()) return $default;
            return $item['value'];
        }
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $expires = time() + max(1, $ttl);
        self::$memo[$key] = ['value' => $value, 'expires' => $expires];
        try {
            Cache::set($key, $value, $ttl);
            unset(self::$fallback[$key]);
        } catch (Throwable) {
            self::$fallback[$key] = ['value' => $value, 'expires' => $expires];
        }
    }

    public function delete(string $key): void
    {
        try { Cache::delete($key); } catch (Throwable) {}
        unset(self::$fallback[$key], self::$memo[$key]);
    }

    public function remember(string $key, int $ttl, Closure $loader): mixed
    {
        $memo = self::$memo[$key] ?? null;
        if ($memo !== null && $memo['expires'] >= time()) return $memo['value'];
        unset(self::$memo[$key]);
        try {
            if (Cache::has($key)) {
                $value = Cache::get($key);
                self::$memo[$key] = ['value' => $value, 'expires' => time() + max(1, $ttl)];
                return $value;
            }

            $value = $loader();
            $this->set($key, $value, $ttl);
            return $value;
        } catch (Throwable) {
            $cached = $this->get($key, null);
            if ($cached !== null) return $cached;
            $value = $loader();
            $this->set($key, $value, $ttl);
            return $value;
        }
    }
}
