<?php
declare(strict_types=1);

namespace tests;

use app\service\FileRateLimitStore;
use PHPUnit\Framework\TestCase;

final class FileRateLimitStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/feifei-rate-limit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testItPersistsAndClearsAttemptsAcrossInstances(): void
    {
        $first = new FileRateLimitStore($this->directory);
        self::assertSame(0, $first->attempts('login:127.0.0.1'));
        self::assertSame(1, $first->hit('login:127.0.0.1', 60));
        self::assertSame(2, $first->hit('login:127.0.0.1', 60));

        $second = new FileRateLimitStore($this->directory);
        self::assertSame(2, $second->attempts('login:127.0.0.1'));
        $second->clear('login:127.0.0.1');
        self::assertSame(0, $first->attempts('login:127.0.0.1'));
    }
}
