<?php
declare(strict_types=1);

namespace tests;

use app\service\PlaybackPosition;
use PHPUnit\Framework\TestCase;

final class PlaybackPositionTest extends TestCase
{
    public function testPublicSourceNumbersStartAtOne(): void
    {
        self::assertSame(1, PlaybackPosition::publicSourceNumber(0));
        self::assertSame(3, PlaybackPosition::publicSourceNumber(2));
        self::assertSame(0, PlaybackPosition::internalSourceIndex(1));
        self::assertSame(2, PlaybackPosition::internalSourceIndex(3));
        self::assertNull(PlaybackPosition::internalSourceIndex(0));
    }

    public function testPublicEpisodeNumbersStartAtOne(): void
    {
        self::assertSame(1, PlaybackPosition::publicEpisodeNumber(0));
        self::assertSame(5, PlaybackPosition::publicEpisodeNumber(4));
        self::assertSame(1, PlaybackPosition::publicEpisodeNumber(-5));
    }

    public function testPublicEpisodeNumbersMapBackToRuntimeIndexes(): void
    {
        self::assertSame(0, PlaybackPosition::internalEpisodeIndex(1));
        self::assertSame(4, PlaybackPosition::internalEpisodeIndex(5));
        self::assertNull(PlaybackPosition::internalEpisodeIndex(0));
    }

    public function testTemplateHelperUsesOneBasedPublicEpisodeNumber(): void
    {
        require_once dirname(__DIR__) . '/app/common.php';

        self::assertSame('/vod/play/id/16/sid/1/pid/1.html', ff_play_url(16, 0, 0));
    }
}
