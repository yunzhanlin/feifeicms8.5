<?php
declare(strict_types=1);

use app\service\FrontendCache;
use app\service\ResilientCache;
use PHPUnit\Framework\TestCase;

final class FrontendCacheTest extends TestCase
{
    private FrontendCache $cache;

    protected function setUp(): void
    {
        $this->cache = new FrontendCache(new ResilientCache());
        $this->cache->invalidateCategories();
        $this->cache->invalidateSettings();
    }

    protected function tearDown(): void
    {
        $this->cache->invalidateCategories();
        $this->cache->invalidateSettings();
    }

    public function testHomePayloadIsLoadedOnceUntilInvalidated(): void
    {
        $loads = 0;
        $loader = static function () use (&$loads): array {
            return ['generation' => ++$loads];
        };

        self::assertSame(['generation' => 1], $this->cache->home($loader));
        self::assertSame(['generation' => 1], $this->cache->home($loader));
        self::assertSame(1, $loads);

        $this->cache->invalidateHome();
        self::assertSame(['generation' => 2], $this->cache->home($loader));
    }

    public function testCategoryInvalidationAlsoExpiresSharedAndHomeData(): void
    {
        $categories = $shared = $home = 0;
        $categoryLoader = static function () use (&$categories): int { return ++$categories; };
        $sharedLoader = static function () use (&$shared): int { return ++$shared; };
        $homeLoader = static function () use (&$home): int { return ++$home; };
        self::assertSame(1, $this->cache->categories($categoryLoader));
        self::assertSame(1, $this->cache->shared($sharedLoader));
        self::assertSame(1, $this->cache->home($homeLoader));

        $this->cache->invalidateCategories();

        self::assertSame(2, $this->cache->categories($categoryLoader));
        self::assertSame(2, $this->cache->shared($sharedLoader));
        self::assertSame(2, $this->cache->home($homeLoader));
    }

    public function testFrontendConversionUsesCachedCategoryMapInsteadOfPerItemQuery(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/service/FrontendData.php');
        self::assertStringContainsString('$this->categoryNames[(int) $media[\'category_id\']]', $source);
        self::assertStringNotContainsString("Db::table('ffx_categories')->where('id'", $source);
        self::assertStringContainsString("LegacyUrlGenerator::prime('ffx_media'", $source);
    }

    public function testHomeCategoryQueriesAreInsideTheCachedLoader(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/controller/Index.php');
        $cacheStart = strpos($source, '$this->cache->home(');
        $channelQuery = strpos($source, "->where('category_id'", $cacheStart ?: 0);
        $loaderEnd = strpos($source, '});', $cacheStart ?: 0);
        self::assertIsInt($cacheStart);
        self::assertIsInt($channelQuery);
        self::assertIsInt($loaderEnd);
        self::assertGreaterThan($cacheStart, $channelQuery);
        self::assertLessThan($loaderEnd, $channelQuery);
    }
}
