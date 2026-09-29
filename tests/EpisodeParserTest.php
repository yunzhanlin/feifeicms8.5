<?php
declare(strict_types=1);

namespace tests;

use app\service\EpisodeParser;
use PHPUnit\Framework\TestCase;

final class EpisodeParserTest extends TestCase
{
    public function testItPreservesSourcesAndEpisodeIndexes(): void
    {
        $sources = (new EpisodeParser())->parse(
            'm3u8$$$mp4',
            '第1集$https://a.test/1.m3u8#第2集$https://a.test/2.m3u8$$$正片$https://b.test/movie.mp4'
        );

        self::assertCount(2, $sources);
        self::assertSame(0, $sources[0]['index']);
        self::assertSame('第2集', $sources[0]['episodes'][1]['label']);
        self::assertSame(1, $sources[0]['episodes'][1]['index']);
        self::assertSame('mp4', $sources[1]['key']);
    }

    public function testItDoesNotInventEpisodesFromTotals(): void
    {
        $sources = (new EpisodeParser())->parse('m3u8', 'https://a.test/only.m3u8');
        self::assertCount(1, $sources[0]['episodes']);
        self::assertSame('第1集', $sources[0]['episodes'][0]['label']);
    }

    public function testItKeepsDollarCharactersAfterTheFirstSeparator(): void
    {
        $sources = (new EpisodeParser())->parse('signed', '正片$https://a.test/video?token=a$b$c');
        self::assertSame('https://a.test/video?token=a$b$c', $sources[0]['episodes'][0]['url']);
    }

    public function testItAcceptsFeifeiCarriageReturnEpisodeRows(): void
    {
        $sources = (new EpisodeParser())->parse('m3u8', "第1集\$https://a.test/1.m3u8\r第2集\$https://a.test/2.m3u8");
        self::assertCount(2, $sources[0]['episodes']);
        self::assertSame('第2集', $sources[0]['episodes'][1]['label']);
    }
}
