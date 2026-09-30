<?php
declare(strict_types=1);

namespace app\service;

final class CollectionResponseSizeGuard
{
    public const DEFAULT_MAX_BYTES = 5_242_880;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('采集响应上限必须大于 0');
        }
    }

    public function progress(int $downloadTotal, int $downloadedBytes): void
    {
        if ($downloadTotal > $this->maxBytes || $downloadedBytes > $this->maxBytes) {
            throw new \RuntimeException('采集响应超过 5MB');
        }
    }

    public function assertContentLength(int $contentLength): void
    {
        if ($contentLength > $this->maxBytes) {
            throw new \RuntimeException('采集响应超过 5MB');
        }
    }

    public function assertBody(string $body): void
    {
        if (strlen($body) > $this->maxBytes) {
            throw new \RuntimeException('采集响应超过 5MB');
        }
    }
}
