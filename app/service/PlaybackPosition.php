<?php
declare(strict_types=1);

namespace app\service;

/** Maps zero-based runtime source/episode indexes to one-based public URL numbers. */
final class PlaybackPosition
{
    public static function publicSourceNumber(int|string $internalIndex): int
    {
        return max(0, (int) $internalIndex) + 1;
    }

    public static function internalSourceIndex(int|string $publicNumber): ?int
    {
        $number = (int) $publicNumber;
        return $number >= 1 ? $number - 1 : null;
    }

    public static function publicEpisodeNumber(int|string $internalIndex): int
    {
        return max(0, (int) $internalIndex) + 1;
    }

    public static function internalEpisodeIndex(int|string $publicNumber): ?int
    {
        $number = (int) $publicNumber;
        return $number >= 1 ? $number - 1 : null;
    }
}
