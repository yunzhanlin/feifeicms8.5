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
        try { Cache::set($key, $value, $ttl); } catch (Throwable) { self::$fallback[$key] = ['value' => $value, 'expires' => time() + max(1, $ttl)]; }
    }

    public function delete(string $key): void
    {
        try { Cache::delete($key); } catch (Throwable) {}
        unset(self::$fallback[$key]);
    }

    public function remember(string $key, int $ttl, Closure $loader): mixed
    {
        try {
            if (Cache::has($key)) {
                return Cache::get($key);
            }

            $value = $loader();
            Cache::set($key, $value, $ttl);
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
