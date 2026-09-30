<?php
declare(strict_types=1);

namespace app\service;

use Closure;

/**
 * Cache boundary for public, reusable frontend data.
 *
 * Fixed keys are intentional: every admin write can invalidate the exact
 * affected data without relying on Redis-only wildcard deletion or tags.
 */
final class FrontendCache
{
    public const CATEGORIES_KEY = 'frontend:categories:v1';
    public const SHARED_KEY = 'frontend:shared:v1';
    public const HOME_KEY = 'frontend:home:v3';
    public const SETTINGS_KEY = 'frontend:settings:v1';

    public const CATEGORIES_TTL = 600;
    public const SHARED_TTL = 300;
    public const HOME_TTL = 120;
    public const SETTINGS_TTL = 300;

    public function __construct(private readonly ResilientCache $cache) {}

    public function categories(Closure $loader): mixed
    {
        return $this->cache->remember(self::CATEGORIES_KEY, self::CATEGORIES_TTL, $loader);
    }

    public function shared(Closure $loader): mixed
    {
        return $this->cache->remember(self::SHARED_KEY, self::SHARED_TTL, $loader);
    }

    public function home(Closure $loader): mixed
    {
        return $this->cache->remember(self::HOME_KEY, self::HOME_TTL, $loader);
    }

    public function settings(Closure $loader): mixed
    {
        return $this->cache->remember(self::SETTINGS_KEY, self::SETTINGS_TTL, $loader);
    }

    public function invalidateCategories(): void
    {
        $this->cache->delete(self::CATEGORIES_KEY);
        $this->cache->delete(self::SHARED_KEY);
        $this->cache->delete(self::HOME_KEY);
    }

    public function invalidateShared(): void
    {
        $this->cache->delete(self::SHARED_KEY);
    }

    public function invalidateHome(): void
    {
        $this->cache->delete(self::HOME_KEY);
    }

    public function invalidateSettings(): void
    {
        $this->cache->delete(self::SETTINGS_KEY);
        $this->cache->delete(self::SHARED_KEY);
        $this->cache->delete(self::HOME_KEY);
    }
}
