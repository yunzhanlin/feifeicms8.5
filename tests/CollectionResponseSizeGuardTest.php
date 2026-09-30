<?php
declare(strict_types=1);

namespace tests;

use app\service\CollectionResponseSizeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CollectionResponseSizeGuardTest extends TestCase
{
    /** @return array<string, array{int,int}> */
    public static function oversizedProgress(): array
    {
        return [
            'declared total' => [101, 0],
            'chunked download' => [0, 101],
        ];
    }

    #[DataProvider('oversizedProgress')]
    public function testItStopsDeclaredAndChunkedOversizedDownloads(int $total, int $downloaded): void
    {
        $this->expectException(\RuntimeException::class);
        (new CollectionResponseSizeGuard(100))->progress($total, $downloaded);
    }

    public function testItChecksHeadersAndFinalBodyAsDefenseInDepth(): void
    {
        $guard = new CollectionResponseSizeGuard(4);
        $guard->assertContentLength(4);
        $guard->assertBody('1234');
        self::assertTrue(true);

        $this->expectException(\RuntimeException::class);
        $guard->assertBody('12345');
    }
}
