<?php
declare(strict_types=1);

namespace tests;

use app\service\FrontendCache;
use app\service\FrontendData;
use app\service\PlaybackPlayerRegistry;
use app\service\ResilientCache;
use app\service\SiteSettings;
use PHPUnit\Framework\TestCase;

final class PlaybackPlayerRegistryTest extends TestCase
{
    public function testEmptyRestrictionOnlyAllowsRegisteredPlayers(): void
    {
        self::assertSame(['bfzym3u8'], PlaybackPlayerRegistry::collectionKeys(['bfzym3u8'], ''));
        self::assertSame([], PlaybackPlayerRegistry::collectionKeys([], ''));
        self::assertSame([], PlaybackPlayerRegistry::collectionKeys([], 'bfzym3u8'));
        self::assertSame([], PlaybackPlayerRegistry::collectionKeys(['bfzym3u8'], 'unknown'));
    }

    public function testRestrictionCannotIntroduceAnUnregisteredPlayer(): void
    {
        self::assertSame(['bfzym3u8'], PlaybackPlayerRegistry::collectionKeys(['bfzym3u8', 'mp4'], "BFZYM3U8，unknown\n"));
        self::assertSame([], PlaybackPlayerRegistry::collectionKeys(['bfzym3u8'], 'bfzym3u8-c2-2'));
    }

    public function testMalformedKeysAreRejectedNotSilentlyRewritten(): void
    {
        foreach (['', 'bfzym3u8@', 'bfzy m3u8', 'bfzy/m3u8', "bfzy\0m3u8", str_repeat('x', 81)] as $key) self::assertSame('', PlaybackPlayerRegistry::normalizeKey($key));
        self::assertSame('bfzym3u8', PlaybackPlayerRegistry::normalizeKey(' BFZYM3U8 '));
        self::assertSame('bfzym3u8-c2-2', PlaybackPlayerRegistry::normalizeKey('bfzym3u8-c2-2'));
    }

    public function testCollisionAndMergeKeysAreNotPlayerIdentities(): void
    {
        foreach (['bfzym3u8-c2-2', 'bfzym3u8_merge_1028'] as $key) self::assertSame('bfzym3u8', PlaybackPlayerRegistry::sourceKey(['source_key' => $key, 'parser_key' => 'bfzym3u8']));
        self::assertSame('bfzym3u8', PlaybackPlayerRegistry::sourceKey(['source_key' => 'bfzym3u8', 'parser_key' => '']));
        self::assertSame('unknown', PlaybackPlayerRegistry::sourceKey(['source_key' => 'bfzym3u8', 'parser_key' => 'unknown']));
        self::assertSame('', PlaybackPlayerRegistry::sourceKey(['source_key' => 'bfzym3u8', 'parser_key' => 'invalid@']));
    }

    public function testHistoricalSourcesAreFilteredWithoutChangingIdsOrOrder(): void
    {
        $sources = [
            ['id' => 1, 'source_key' => 'unknown', 'parser_key' => '', 'status' => 'enabled'],
            ['id' => 2, 'source_key' => 'bfzym3u8', 'parser_key' => '', 'status' => 'enabled'],
            ['id' => 3, 'source_key' => 'bfzym3u8-c2-2', 'parser_key' => 'bfzym3u8', 'status' => 'enabled'],
            ['id' => 4, 'source_key' => 'bfzym3u8', 'parser_key' => 'unknown', 'status' => 'enabled'],
            ['id' => 5, 'source_key' => 'bfzym3u8', 'parser_key' => '', 'status' => 'disabled'],
        ];
        self::assertSame([2, 3], array_column(PlaybackPlayerRegistry::filterSources($sources, ['bfzym3u8']), 'id'));
        self::assertSame([], PlaybackPlayerRegistry::filterSources($sources, []));
        self::assertSame([], PlaybackPlayerRegistry::filterSources([], []));
    }

    public function testFrontendUsesRegisteredParserNotPrivateLineKey(): void
    {
        $cache = new FrontendCache(new ResilientCache());
        $frontend = new FrontendData(new SiteSettings($cache), $cache);
        $sources = $frontend->sources([['id' => 1028, 'source_key' => 'bfzym3u8-c2-2', 'parser_key' => 'bfzym3u8', 'display_name' => 'bfzy', 'episodes' => [['id' => 9, 'label' => '第1集', 'media_url' => 'https://example.invalid/1.m3u8']]]]);
        self::assertSame('bfzym3u8', $sources[0]['code']);
        self::assertSame('bfzym3u8-c2-2', $sources[0]['source_key']);
        self::assertSame(9, $sources[0]['episodes'][0]['id']);
    }
}
