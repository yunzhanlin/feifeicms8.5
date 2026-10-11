<?php
declare(strict_types=1);

namespace tests;

use app\service\DoubanMetadata;
use app\service\FrontendCache;
use app\service\ResilientCache;
use app\service\SiteSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class DoubanMetadataTest extends TestCase
{
    private function service(array $values = []): DoubanMetadata
    {
        $settings = new SiteSettings(new FrontendCache(new ResilientCache()));
        (new ReflectionProperty(SiteSettings::class, 'values'))->setValue($settings, $values);
        return new DoubanMetadata($settings);
    }

    private function client(array $responses, array &$history): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($history));
        return new Client(['handler' => $handler]);
    }

    private function payload(string $id = '35465232'): array
    {
        return [
            'id' => $id, 'title' => '狂飙', 'year' => 2023, 'countries' => ['大陆'],
            'languages' => ['汉语普通话'], 'genres' => ['剧情', '犯罪'],
            'actors' => [['name' => '张译'], ['name' => '张颂文']], 'directors' => [['name' => '徐纪周']],
            'writers' => [['name' => '朱俊懿']], 'episodes_count' => 39,
            'pubdate' => ['2023-01-14(中国大陆)'], 'durations' => ['45分钟'],
            'intro' => '剧情简介', 'pic' => ['large' => 'https://img.example.test/poster.jpg'],
        ];
    }

    private function subjectHtml(bool $complete = true): string
    {
        $optional = $complete ? '<span class="pl">主演</span>: <span class="attrs"><a rel="v:starring">张译</a> / <a rel="v:starring">张颂文</a></span><br>
<span class="pl">编剧</span>: <span class="attrs"><a>朱俊懿</a> / <a>徐纪周</a></span><br>
<span class="pl">类型:</span> <span property="v:genre">剧情</span> / <span property="v:genre">犯罪</span><br>
<span class="pl">制片国家/地区:</span> 中国大陆 / 香港<br><span class="pl">语言:</span> 汉语普通话<br>
<span class="pl">首播:</span> <span property="v:initialReleaseDate" content="2023-01-14(中国大陆)">2023-01-14</span><br>
<span class="pl">单集片长:</span> 45分钟<br>' : '';
        return '<!doctype html><html><head><title>狂飙 (豆瓣)</title><meta name="keywords" content="狂飙,剧情,犯罪"><link rel="canonical" href="https://movie.douban.com/subject/35465232/"></head><body>
<div id="content"><h1><span property="v:itemreviewed">狂飙 The Knockout</span><span class="year">(2023)</span></h1>
<div id="mainpic"><img src="https://img.example.test/page.jpg"></div><div id="info">
<span><span class="pl">导演</span>: <span class="attrs"><a rel="v:directedBy">徐纪周</a></span></span><br>' . $optional . '
<span class="pl">集数:</span> 39<br><span class="pl">IMDb:</span> <a href="https://www.imdb.com/title/tt24037718/">tt24037718</a><br>
<span class="pl">又名:</span> The Knockout / 狂飙别名</div>
<strong property="v:average">8.5</strong><span property="v:votes">1,234</span>
<span property="v:summary">简短简介</span><span class="all hidden">完整简介 &amp; 保留条目原文。</span></div></body></html>';
    }

    public function testMapsDoubanPayloadToVideoEditorFields(): void
    {
        $mapped = $this->service()->mapPayload('1292052', [
            'title' => '测试影片',
            'original_title' => 'Test Film',
            'aka' => ['测试别名'],
            'year' => 2026,
            'release_date' => ['2026-09-29(中国大陆)'],
            'countries' => ['中国大陆'],
            'languages' => ['汉语普通话'],
            'genres' => ['剧情', '悬疑'],
            'tags' => [['name' => '经典'], ['name' => '推理']],
            'directors' => [['name' => '导演甲']],
            'actors' => [['name' => '演员甲'], ['name' => '演员乙']],
            'writers' => [['name' => '编剧甲']],
            'imdb_id' => 'IMDb: tt1234567',
            'pic' => ['large' => 'https://img.example.test/poster.jpg'],
            'rating' => ['value' => 8.7, 'count' => 1234],
            'durations' => ['98分钟'],
            'episodes_count' => 12,
            'episodes_info' => '更新至第8集',
            'intro' => '影片简介',
        ]);

        self::assertSame('1292052', $mapped['douban_id']);
        self::assertSame('测试影片', $mapped['title']);
        self::assertSame('Test Film / 测试别名', $mapped['subtitle']);
        self::assertSame('2026-09-29', $mapped['release_date']);
        self::assertSame('剧情,悬疑', $mapped['type']);
        self::assertSame('经典,推理', $mapped['tags']);
        self::assertSame('导演甲', $mapped['director']);
        self::assertSame('演员甲,演员乙', $mapped['actor']);
        self::assertSame('编剧甲', $mapped['writer']);
        self::assertSame('tt1234567', $mapped['imdb_id']);
        self::assertSame(5880, $mapped['length']);
        self::assertSame('[douban]=[1292052]', $mapped['source_ref']);
    }

    public function testUnifiedSubjectPageDoesNotRequireSelectingMovieOrTv(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->subjectHtml())], $history);
        $mapped = $this->service()->fetch('35465232', $client);
        self::assertCount(1, $history);
        self::assertSame('https://movie.douban.com/subject/35465232/', (string) $history[0]['request']->getUri());
        self::assertSame('狂飙', $mapped['title']);
        self::assertSame('张译,张颂文', $mapped['actor']);
        self::assertSame('朱俊懿,徐纪周', $mapped['writer']);
        self::assertSame('tt24037718', $mapped['imdb_id']);
        self::assertSame('完整简介 & 保留条目原文。', $mapped['content']);
        self::assertSame('中国大陆,中国香港', $mapped['area']);
        self::assertSame('国语', $mapped['language']);
        self::assertSame(1234, $mapped['rating_count']);
        self::assertSame(39, $mapped['episode_total']);
        self::assertSame(2700, $mapped['length']);
        self::assertSame('2023-01-14', $mapped['release_date']);
    }

    public function testAutomaticallyFollowsDoubanTypeRedirectAndUsesConfiguredCookie(): void
    {
        $history = [];
        $client = $this->client([
            new Response(302, ['Location' => 'https://sec.douban.com/c']),
            new Response(301, ['Location' => '/rexxar/api/v2/tv/35465232']),
            new Response(200, [], json_encode($this->payload())),
        ], $history);
        $mapped = $this->service(['admin.base.douban_cookie' => "fixture=value;\r\n bid=example"])->fetch('35465232', $client);
        self::assertSame('狂飙', $mapped['title']);
        self::assertSame(39, $mapped['episode_total']);
        self::assertSame('2023-01-14', $mapped['release_date']);
        self::assertCount(3, $history);
        self::assertSame('/rexxar/api/v2/tv/35465232', $history[2]['request']->getUri()->getPath());
        foreach ($history as $request) {
            self::assertSame('fixture=value; bid=example', $request['request']->getHeaderLine('Cookie'));
            self::assertFalse($request['options']['allow_redirects']);
            self::assertTrue($request['options']['verify']);
            self::assertLessThanOrEqual(8, $request['options']['timeout']);
        }
    }

    public function testJsonFallbackPreservesSubjectPageFieldsAndFillsMissingCast(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], $this->subjectHtml(false)), new Response(200, [], json_encode($this->payload()))], $history);
        $mapped = $this->service()->fetch('35465232', $client);
        self::assertSame('张译,张颂文', $mapped['actor']);
        self::assertSame('朱俊懿', $mapped['writer']);
        self::assertSame('https://img.example.test/page.jpg', $mapped['poster_url']);
        self::assertSame('完整简介 & 保留条目原文。', $mapped['content']);
        self::assertSame('tt24037718', $mapped['imdb_id']);
        self::assertSame('The Knockout / 狂飙别名', $mapped['subtitle']);
    }

    public function testMovieJsonStillWorksAndDoesNotSendAnEmptyCookie(): void
    {
        $history = [];
        $client = $this->client([new Response(404), new Response(200, [], json_encode($this->payload('1292052')))], $history);
        $mapped = $this->service()->fetch('1292052', $client);
        self::assertSame('1292052', $mapped['douban_id']);
        self::assertSame('[douban]=[1292052]', $mapped['source_ref']);
        foreach ($history as $request) self::assertFalse($request['request']->hasHeader('Cookie'));
    }

    public function testSupplementalCreditsFillWriterButDoNotDiscardValidMetadataOnFailure(): void
    {
        foreach ([new Response(200, [], '{"items":[{"name":"编剧甲","category":"编剧"},{"name":"编剧乙","character":"Writer"},{"name":"演员","category":"演员","character":"演员 Actor (饰 Writer)","roles":["演员","编剧"]},{"name":"导演","category":"导演","character":"导演 Director","roles":["导演","编剧"]}]}'), new Response(404)] as $credits) {
            $history = [];
            $payload = $this->payload(); unset($payload['writers']);
            $client = $this->client([new Response(404), new Response(301, ['Location' => 'https://m.douban.com/rexxar/api/v2/tv/35465232']), new Response(200, [], json_encode($payload)), $credits], $history);
            $mapped = $this->service()->fetch('35465232', $client);
            self::assertSame('狂飙', $mapped['title']);
            self::assertSame($credits->getStatusCode() === 200 ? '编剧甲,编剧乙' : '', $mapped['writer']);
            self::assertSame('/rexxar/api/v2/tv/35465232/credits', $history[3]['request']->getUri()->getPath());
        }
    }

    public function testUnsafeOrDifferentSubjectRedirectsNeverReceiveCookie(): void
    {
        foreach (['https://example.test/rexxar/api/v2/tv/35465232', 'http://m.douban.com/rexxar/api/v2/tv/35465232', 'https://m.douban.com:444/rexxar/api/v2/tv/35465232', '/rexxar/api/v2/tv/1292052', 'https://sec.douban.com/c', '/rexxar/api/v2/tv/35465232?token=private', 'https://user:password@m.douban.com/rexxar/api/v2/tv/35465232'] as $location) {
            $history = [];
            $client = $this->client([new Response(404), new Response(301, ['Location' => $location])], $history);
            try { $this->service(['admin.base.douban_cookie' => 'private=fixture'])->fetch('35465232', $client); self::fail('Unsafe redirect accepted'); }
            catch (RuntimeException $exception) { self::assertStringContainsString('跳转', $exception->getMessage()); }
            self::assertCount(2, $history);
        }
    }

    public function testRedirectLoopsAreBounded(): void
    {
        $history = [];
        $client = $this->client([new Response(404), new Response(301, ['Location' => '/rexxar/api/v2/tv/35465232']), new Response(301, ['Location' => '/rexxar/api/v2/movie/35465232'])], $history);
        $this->expectExceptionMessage('跳转过多');
        try { $this->service()->fetch('35465232', $client); }
        finally { self::assertCount(3, $history); }
    }

    public function testInvalidIdsDoNotMakeAnyNetworkRequest(): void
    {
        foreach (['', '0', '000001', '1234', '35465232/credits', 'https://movie.douban.com/subject/35465232/', "35465232\n"] as $id) {
            $history = [];
            try { $this->service()->fetch($id, $this->client([], $history)); self::fail('Invalid ID accepted'); }
            catch (RuntimeException $exception) { self::assertSame('豆瓣 ID 格式无效', $exception->getMessage()); }
            self::assertSame([], $history);
        }
    }

    public function testRateLimitAndAuthenticationFailuresStopWithoutRepeatedRequests(): void
    {
        foreach ([new Response(429), new Response(403), new Response(200, [], '错误代码：004')] as $blocked) {
            $history = [];
            try { $this->service()->fetch('35465232', $this->client([$blocked], $history)); self::fail('Blocked response accepted'); }
            catch (RuntimeException $exception) { self::assertSame(429, $exception->getCode()); }
            self::assertCount(1, $history);
        }
    }

    public function testMalformedOrWrongSubjectJsonDoesNotFillTheEditor(): void
    {
        foreach (['<html>登录验证</html>', '{}', '{"title":""}', json_encode($this->payload('1292052'))] as $invalid) {
            $history = [];
            try { $this->service()->fetch('35465232', $this->client([new Response(404), new Response(200, [], $invalid)], $history)); self::fail('Invalid JSON filled the editor'); }
            catch (RuntimeException $exception) { self::assertStringContainsString('资料', $exception->getMessage()); }
            self::assertCount(2, $history);
        }
    }

    public function testNetworkErrorsDoNotExposeSecretsAndPartialHtmlIsRetained(): void
    {
        foreach ([new Response(404), new Response(200, [], $this->subjectHtml(false))] as $page) {
            $history = [];
            $client = $this->client([$page, new ConnectException('token=private-credential', new Request('GET', 'https://m.douban.com/'))], $history);
            try {
                $mapped = $this->service()->fetch('35465232', $client);
                self::assertSame(200, $page->getStatusCode());
                self::assertSame('狂飙', $mapped['title']);
            } catch (RuntimeException $exception) {
                self::assertSame(404, $page->getStatusCode());
                self::assertStringNotContainsString('private-credential', $exception->getMessage());
                self::assertStringContainsString('连接豆瓣', $exception->getMessage());
            }
        }
    }

    public function testResponseSizeIsBoundedAndStreamsAreClosed(): void
    {
        $history = [];
        $body = Utils::streamFor(str_repeat('x', 2 * 1024 * 1024 + 1));
        try { $this->service()->fetch('35465232', $this->client([new Response(404), new Response(200, [], $body)], $history)); self::fail('Oversized response accepted'); }
        catch (RuntimeException $exception) { self::assertStringContainsString('响应过大', $exception->getMessage()); }
        self::assertFalse($body->isReadable());
    }

    public function testOnlyValidReleaseDatesAreUsedAndRereleaseDoesNotReplaceOriginalYear(): void
    {
        $mapped = $this->service()->mapPayload('1292052', ['title' => '测试', 'year' => 1994, 'pubdate' => ['2024-10-01', '1994-09-10', '1994-09-23', '1994-02-30']]);
        self::assertSame('1994-09-10', $mapped['release_date']);
        self::assertSame('', $this->service()->mapPayload('1292052', ['title' => '测试', 'year' => 1994, 'pubdate' => ['1994']])['release_date']);
        self::assertSame('', $this->service()->mapPayload('1292052', ['title' => '测试', 'year' => 1994, 'pubdate' => ['2026-08-28']])['release_date']);
    }

    public function testLoginPageAndDifferentSubjectPageAreNotMetadata(): void
    {
        self::assertSame([], $this->service()->mapSubjectPage('35465232', '<h1>登录豆瓣</h1>'));
        self::assertSame([], $this->service()->mapSubjectPage('1292052', $this->subjectHtml()));
    }

    public function testInvalidCookieDoesNotLeakItsValueOrReachTheNetwork(): void
    {
        $history = [];
        try { $this->service(['admin.base.douban_cookie' => "private=credential\0bad"])->fetch('35465232', $this->client([], $history)); self::fail('Invalid Cookie accepted'); }
        catch (RuntimeException $exception) {
            self::assertStringContainsString('Cookie', $exception->getMessage());
            self::assertStringNotContainsString('credential', $exception->getMessage());
        }
        self::assertSame([], $history);
    }
}
