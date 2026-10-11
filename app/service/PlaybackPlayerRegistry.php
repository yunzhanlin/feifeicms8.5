<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Player identity is not the per-video unique playback-line identifier. */
final class PlaybackPlayerRegistry
{
    public static function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        // Reject malformed upstream names instead of rewriting them into a registered player.
        return preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/D', $value) ? $value : '';
    }

    /** @return list<string> */
    public static function enabledKeys(): array
    {
        return array_values(array_unique(array_filter(array_map(
            self::normalizeKey(...), Db::table('ffx_players')->where('status', 'enabled')->column('player_key')
        ))));
    }

    /** A nonempty parser_key is authoritative; never strip guessed collision suffixes. */
    public static function sourceKey(array $source): string
    {
        $parser = trim((string) ($source['parser_key'] ?? ''));
        return self::normalizeKey($parser !== '' ? $parser : (string) ($source['source_key'] ?? ''));
    }

    /** Allocate a private line key without overwriting another group's episodes. */
    public static function uniqueLineKey(int $mediaId, string $base, int $ignoreId = 0): string
    {
        for ($suffix = 0; $suffix < 1000; $suffix++) {
            $tail = $suffix === 0 ? '' : '-line-' . ($suffix + 1);
            $key = mb_substr($base, 0, 80 - strlen($tail)) . $tail;
            $query = Db::table('ffx_play_sources')->where('media_id', $mediaId)->where('source_key', $key);
            if ($ignoreId > 0) $query->where('id', '<>', $ignoreId);
            if ($query->count() === 0) return $key;
        }
        throw new \RuntimeException('该播放器的线路组过多，请先整理播放地址');
    }

    /** An optional collection restriction can narrow, but never expand, registered players.
     *  @param list<string> $registered @return list<string>
     */
    public static function collectionKeys(array $registered, string $restriction): array
    {
        $registered = array_values(array_unique(array_filter(array_map(self::normalizeKey(...), $registered))));
        if (trim($restriction) === '') return $registered;
        $requested = array_filter(array_map(self::normalizeKey(...), preg_split('/[\s,，]+/u', $restriction) ?: []));
        return array_values(array_intersect($registered, $requested));
    }

    /** @param list<array<string,mixed>> $sources @param list<string>|null $registered
     *  @return list<array<string,mixed>>
     */
    public static function filterSources(array $sources, ?array $registered = null): array
    {
        if ($sources === []) return [];
        $allowed = array_fill_keys($registered ?? self::enabledKeys(), true);
        return array_values(array_filter($sources, static fn (array $source): bool =>
            ($source['status'] ?? 'enabled') === 'enabled' && isset($allowed[self::sourceKey($source)])
        ));
    }
}
