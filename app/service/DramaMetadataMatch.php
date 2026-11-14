<?php
declare(strict_types=1);

namespace app\service;

final class DramaMetadataMatch
{
    public function __construct(private readonly TvznMetadata $tvzn, private readonly TmdbMetadata $tmdb) {}

    public function collect(array $media, string $provider, bool $plots, bool $cast, ?string $requiredType = null, ?int $selectedSeason = null): ?array
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            [$media['title'], $media['original_title'] ?? '']
        ))));
        $season = $selectedSeason;
        if ($provider === 'tmdb') {
            foreach ($names as $name) {
                if (preg_match('/^(.*?)(?:\s*第\s*([0-9]+|[一二三四五六七八九十两]+)\s*季|\s+season\s+([0-9]+)|\s+s([0-9]{1,3}))$/iu', $name, $match)) {
                    $names[] = trim($match[1]);
                    $season ??= $this->seasonNumber(($match[2] ?? '') ?: (($match[3] ?? '') ?: ($match[4] ?? '')));
                    break;
                }
            }
        }

        $imdb = (string) ($media['imdb_id'] ?? '');
        $strong = $provider === 'tmdb' && preg_match('/^tt[0-9]{5,12}$/D', $imdb);
        $rows = $strong ? $this->tmdb->findImdb($imdb) : [];
        if ($rows === []) {
            $strong = false;
            $rows = [];
            foreach ($names as $name) {
                $matches = $provider === 'tvzn' ? $this->tvzn->search($name) : $this->tmdb->searchAll($name);
                foreach ($matches as $row) {
                    $rows[($row['type'] ?? 'tv') . ':' . $row['id']] = $row;
                }
            }
        }
        $year = (int) ($media['release_year'] ?? 0);
        $local = array_map($this->normalize(...), $names);
        $choices = array_values(array_filter($rows, function (array $row) use ($local, $year, $season, $strong, $requiredType): bool {
            if ($requiredType !== null && ($row['type'] ?? 'tv') !== $requiredType) return false;
            if ($strong) return true;
            $remote = array_map($this->normalize(...), [$row['title'], $row['original_title'] ?? '']);
            if (array_intersect($local, array_filter($remote)) === []) return false;
            $remoteYear = (int) ($row['year'] ?? 0);
            return $year === 0 || $remoteYear === 0 || ($season !== null && $season > 1) || $year === $remoteYear;
        }));
        if (count($choices) !== 1) return null;

        $choice = $choices[0];
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string) $choice['id'])) return null;
        if ($provider === 'tvzn') {
            $data = $this->tvzn->preview((string) $choice['id'], $plots, $cast);
            if ($year > 0 && !empty($data['year']) && $year !== $data['year']) return null;
            return $data;
        }

        $type = $choice['type'];
        if ($type === 'movie' && ($season !== null || (int) ($media['episode_total'] ?? 0) > 1)) return null;
        $data = $this->tmdb->preview((string) $choice['id'], $type, $season ?? -1, $plots && $type === 'tv', $cast);
        return $data['season_found'] ? $data : null;
    }

    private function normalize(string $title): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{Z}]+/u', '', trim($title)));
    }

    private function seasonNumber(string $value): int
    {
        if (ctype_digit($value)) return (int) $value;
        $digits = ['' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5,
            '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        if (!str_contains($value, '十')) return $digits[$value] ?? -2;
        [$tens, $units] = explode('十', $value, 2);
        if (!isset($digits[$tens], $digits[$units])) return -2;
        return (int) (($digits[$tens] ?: 1) * 10 + $digits[$units]);
    }
}
