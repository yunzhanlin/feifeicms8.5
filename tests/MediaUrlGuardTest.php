<?php
declare(strict_types=1);

namespace tests;

use app\service\MediaUrlGuard;
use PHPUnit\Framework\TestCase;

final class MediaUrlGuardTest extends TestCase
{
    public function testItAllowsOnlyHttpMediaUrls(): void
    {
        $guard = new MediaUrlGuard();
        self::assertSame('https://cdn.test/a.m3u8', $guard->allow('https://cdn.test/a.m3u8'));
        self::assertNull($guard->allow('javascript:alert(1)'));
        self::assertNull($guard->allow('file:///etc/passwd'));
        self::assertNull($guard->allow('/relative/video.mp4'));
    }
}
