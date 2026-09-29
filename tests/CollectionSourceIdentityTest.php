<?php
declare(strict_types=1);

use app\service\CollectionSourceIdentity;
use PHPUnit\Framework\TestCase;

final class CollectionSourceIdentityTest extends TestCase
{
    private CollectionSourceIdentity $identity;

    protected function setUp(): void
    {
        $this->identity = new CollectionSourceIdentity();
    }

    public function testBuildsClassicFeifeiMarkerWhenUpstreamOmitsVodReurl(): void
    {
        self::assertSame('[bfzy]=[157252]', $this->identity->marker('bfzy', 2, '157252'));
        self::assertSame('[source-9]=[abc]', $this->identity->marker('飞飞资源', 9, 'abc'));
    }

    public function testPreservesUpstreamMarkerAndMergesDistinctSources(): void
    {
        self::assertSame('[skk]=[8]', $this->identity->marker('source', 1, '8', '[skk]=[8]'));
        self::assertSame('[bfzy]=[157252],[skk]=[8]', $this->identity->merge('[bfzy]=[157252]', '[skk]=[8]', '[bfzy]=[157252]'));
    }
}
