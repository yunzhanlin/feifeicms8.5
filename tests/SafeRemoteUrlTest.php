<?php
declare(strict_types=1);

namespace tests;

use app\service\SafeRemoteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeRemoteUrlTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/api'],
            'private v4' => ['http://192.168.1.10/api'],
            'loopback v6' => ['http://[::1]/api'],
            'embedded credentials' => ['https://user:pass@example.com/api'],
            'unsupported scheme' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testItRejectsUnsafeCollectorEndpoints(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        (new SafeRemoteUrl())->resolve($url);
    }
}
