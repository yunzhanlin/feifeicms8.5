<?php
declare(strict_types=1);

namespace app\cache;

/**
 * ThinkPHP calculates the first cache key before lazily constructing Predis.
 * Predis then takes over prefixing, so the framework's first write otherwise
 * receives the prefix twice and cannot be read back. Initialise the handler
 * before any key is calculated so every operation uses one stable prefix.
 */
final class PredisRedis extends \think\cache\driver\Redis
{
    private string $namespace;

    public function __construct(array $options = [])
    {
        $this->namespace = (string) ($options['prefix'] ?? '');
        if ($this->namespace === '' || preg_match('/[\x00-\x20*?\[\]\\\\]/', $this->namespace)) {
            throw new \InvalidArgumentException('Redis 缓存必须配置非空、无通配符的独立前缀');
        }
        parent::__construct($options);
        $this->handler();
    }

    /** Never FLUSHDB: other applications may share this Redis database. */
    public function clear(): bool
    {
        $redis = $this->handler();
        // phpredis treats integer/string 0 as an already-completed scan.
        // Only null starts a new iteration; Predis sends the raw cursor 0.
        $cursor = $redis instanceof \Predis\Client ? '0' : null;
        do {
            if ($redis instanceof \Predis\Client) {
                // Raw commands avoid prefixing already-prefixed keys twice.
                [$cursor, $keys] = $redis->executeRaw(['SCAN', $cursor, 'MATCH', $this->namespace . '*', 'COUNT', '500']);
            } else {
                $keys = $redis->scan($cursor, $this->namespace . '*', 500) ?: [];
            }
            $keys = array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, $this->namespace)));
            if ($keys !== []) {
                if ($redis instanceof \Predis\Client) $redis->executeRaw(array_merge(['DEL'], $keys));
                else $redis->del($keys);
            }
        } while ((string) $cursor !== '0');
        return true;
    }
}
