<?php
declare(strict_types=1);

namespace tests;

use app\service\CollectionCategoryMap;
use PHPUnit\Framework\TestCase;

final class CollectionCategoryMapTest extends TestCase
{
    public function testExplicitUnboundSelectionIsPreserved(): void
    {
        $map = new CollectionCategoryMap();
        self::assertSame(['1' => 0, '2' => 7], $map->normalize(['1' => '0', '2' => '7', '3' => '999'], [7, 8]));
    }

    public function testExplicitUnboundSelectionSkipsImport(): void
    {
        $map = new CollectionCategoryMap();
        self::assertSame(0, $map->resolve(['1' => 0], '1', 3));
        self::assertSame(7, $map->resolve(['1' => 7], '1', 3));
        self::assertSame(3, $map->resolve([], '1', 3));
    }
}
