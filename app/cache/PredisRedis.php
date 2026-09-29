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
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->handler();
    }
}
