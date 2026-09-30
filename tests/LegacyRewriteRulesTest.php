<?php
declare(strict_types=1);

namespace tests;

use app\service\LegacyRewriteRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyRewriteRulesTest extends TestCase
{
    private const FEIFEI_74 = <<<'RULES'
list-ename-id-(:letter)===(:letter)/p1
list-ename-id-(:letter)-p-(:num)===(:letter)/p(:num)
news-read-id-(:num)-p-(:num)===zixun/(:num)-(:num)
news-read-id-(:num)===zixun/(:num)
scenario-read-id-(:num)-pid-(:num)===juqing/(:num)-(:num)
scenario-read-id-(:num)===juqing/(:num)
special-read-id-(:num)===zhuanti/(:num)
star-read-id-(:num)===mingxing/(:num)
role-read-id-(:num)===juese/(:num)
guestbook-read-id-(:num)===liuyan/(:num)
forum-read-id-(:num)===forum/(:num)
user-index-id-(:num)===user/(:num)
vod-juqing-id-(:num)===fenji/(:num)
vod-taici-id-(:num)===taici/(:num)
vod-zixun-id-(:num)===yingxun/(:num)
vod-yanyuan-id-(:num)===yanyuan/(:num)
vod-pingfen-id-(:num)===pingfen/(:num)
vod-kandian-id-(:num)===kandian/(:num)
vod-shoubo-id-(:num)===shoubo/(:num)
vod-jieju-id-(:num)===jieju/(:num)
vod-rss-id-(:num)===rss/(:num)
vod-yugao-id-(:num)===yugao/(:num)
vod-xiazai-id-(:num)===xiazai/(:num)
vod-forum-id-(:num)-p-(:num)===yingping(:num)-(:num)
vod-forum-id-(:num)===yingping/(:num)
vod-read-dir-(:letter)-id-(:letternum)===(:letter)/(:letternum)
vod-play-dir-(:letter)-id-(:num)-sid-(:num)-pid-(:num)===(:letter)/(:num)/(:num)-(:num)
RULES;

    public function testItRewritesAndResolvesClassicFeifeiRules(): void
    {
        $source = implode("\n", [
            'vod-read-id-(:num)===video/detail/(:num)',
            'vod-play-id-(:num)-sid-(:num)-pid-(:num)===play/(:num)/(:num)/(:num)',
        ]);
        $rules = new LegacyRewriteRules();

        self::assertSame('video/detail/454', $rules->rewrite('vod-read-id-454', $source));
        self::assertSame('play/454/1/12', $rules->rewrite('vod-play-id-454-sid-1-pid-12', $source));
        self::assertSame('vod-read-id-454', $rules->resolve('video/detail/454', $source));
        self::assertCount(2, $rules->compiled($source)['route_rules']);
    }

    public function testItRejectsMalformedOrAmbiguousRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyRewriteRules())->parse('vod-read-id-(:num)===video/detail');
    }

    public function testItCompilesAllFeifei74RulesAndRoundTripsEveryShape(): void
    {
        $rules = new LegacyRewriteRules();
        self::assertCount(27, $rules->parse(self::FEIFEI_74));
        $cases = [
            'list-ename-id-movies' => 'movies/p1',
            'list-ename-id-movies-p-3' => 'movies/p3',
            'news-read-id-12-p-2' => 'zixun/12-2',
            'scenario-read-id-12-pid-4' => 'juqing/12-4',
            'special-read-id-7' => 'zhuanti/7',
            'vod-forum-id-12-p-3' => 'yingping12-3',
            'vod-read-dir-movies-id-movie12' => 'movies/movie12',
            'vod-play-dir-movies-id-12-sid-2-pid-8' => 'movies/12/2-8',
        ];
        foreach ($cases as $source => $target) {
            self::assertSame($target, $rules->rewrite($source, self::FEIFEI_74), $source);
            self::assertSame($source, $rules->resolve($target, self::FEIFEI_74), $target);
        }
        $resolved = $rules->resolveRoute('movies/12/2-8', self::FEIFEI_74);
        self::assertSame('vod', $resolved['module']);
        self::assertSame('play', $resolved['operation']);
        self::assertSame(['dir' => 'movies', 'id' => '12', 'sid' => '2', 'pid' => '8'], $resolved['params']);
    }

    public function testItSupportsCommentsFixedParametersAndPreview(): void
    {
        $source = "# comment\n// comment\nvod-read-id-(:num)===video/(:num)===from=legacy&mode=full";
        $rules = new LegacyRewriteRules();
        $resolved = $rules->resolveRoute('video/9', $source);
        self::assertSame(['from' => 'legacy', 'mode' => 'full', 'id' => '9'], $resolved['params']);
        self::assertSame('video/12', $rules->preview($source)[0]['sample_target']);
    }

    #[DataProvider('invalidRules')]
    public function testItRejectsUnsafeMismatchedOrDuplicateRules(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new LegacyRewriteRules())->parse($source);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidRules(): iterable
    {
        yield 'placeholder type' => ['vod-read-id-(:num)===video/(:letter)'];
        yield 'placeholder order' => ['vod-play-id-(:num)-sid-(:letter)===play/(:letter)/(:num)'];
        yield 'duplicate source' => ["vod-read-id-(:num)===video/(:num)\nvod-read-id-(:num)===detail/(:num)"];
        yield 'duplicate target' => ["vod-read-id-(:num)===video/(:num)\nnews-read-id-(:num)===video/(:num)"];
        yield 'traversal' => ['vod-read-id-(:num)===../video/(:num)'];
        yield 'scheme' => ['vod-read-id-(:num)===https://example.com/(:num)'];
        yield 'malformed action' => ['vod===video'];
    }
}
