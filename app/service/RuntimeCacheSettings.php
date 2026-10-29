<?php
declare(strict_types=1);

namespace app\service;

use think\App;
use think\facade\Db;
use Throwable;

/** Resolve cache selection before SiteSettings itself starts using the cache. */
final class RuntimeCacheSettings
{
    private readonly array $defaults;

    public function __construct(private readonly App $app)
    {
        $this->defaults = (array) $app->config->get('cache');
    }

    public function apply(): void
    {
        $values = [];
        if (is_file($this->app->getRuntimePath() . 'install.lock')) {
            try {
                // Do not use SiteSettings here: its own cache needs this driver.
                $rows = Db::table('ffx_site_settings')->whereIn('setting_key', [
                    'admin.cache.driver', 'admin.cache.prefix', 'admin.cache.ttl',
                ])->field('setting_key,setting_value')->select()->toArray();
                foreach ($rows as $row) {
                    $value = json_decode((string) $row['setting_value'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_scalar($value)) {
                        $values[substr($row['setting_key'], strlen('admin.cache.'))] = (string) $value;
                    }
                }
            } catch (Throwable) {
                // Empty/unavailable schemas still need installer and diagnostic routes.
            }
        }
        $config = self::resolve($this->defaults, $values);
        if ($config !== $this->app->config->get('cache')) {
            $this->app->config->set($config, 'cache');
            $this->app->cache->forgetDriver(['file', 'redis']);
            ResilientCache::resetLocal();
        }
    }

    /** @param array<string,string> $values */
    public static function resolve(array $defaults, array $values): array
    {
        // Web installation leaves optional Redis fields empty when using files.
        $defaults['stores']['redis']['host'] = trim((string) ($defaults['stores']['redis']['host'] ?? '')) ?: '127.0.0.1';
        if (in_array($values['driver'] ?? '', ['file', 'redis'], true)) $defaults['default'] = $values['driver'];
        foreach (['file', 'redis'] as $store) {
            if (isset($values['prefix']) && self::safePrefix(trim($values['prefix']))) $defaults['stores'][$store]['prefix'] = trim($values['prefix']);
            if (isset($values['ttl']) && ctype_digit($values['ttl'])) $defaults['stores'][$store]['expire'] = min(PHP_INT_MAX, (int) $values['ttl']);
        }
        return $defaults;
    }

    /** Refuse a broken backend before persisting a selection that cannot work. */
    public function validate(array $values): void
    {
        if (isset($values['prefix']) && trim($values['prefix']) !== '' && !self::safePrefix(trim($values['prefix']))) {
            throw new \InvalidArgumentException('缓存前缀只能包含字母、数字、冒号、下划线和连字符');
        }
        $config = self::resolve($this->defaults, $values);
        $driver = $config['default'];
        $options = (array) $config['stores'][$driver];
        $cache = $driver === 'redis' ? new \app\cache\PredisRedis($options) : new \think\cache\driver\File($this->app, $options);
        $key = 'cache-settings:probe:' . bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));
        try {
            if (!$cache->set($key, $value, 10) || $cache->get($key) !== $value) {
                throw new \RuntimeException('缓存读写校验失败');
            }
        } finally { $cache->delete($key); }
    }

    private static function safePrefix(string $prefix): bool
    {
        // File caches use the prefix as a directory; never accept path traversal.
        return preg_match('/^[A-Za-z0-9:_-]{1,100}$/D', $prefix) === 1;
    }
}
