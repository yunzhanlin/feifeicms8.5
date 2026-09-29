<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;
use RuntimeException;

final class DoubanMetadata
{
    public function fetch(string $subjectId): array
    {
        if (!preg_match('/^\d{5,12}$/', $subjectId)) {
            throw new RuntimeException('豆瓣 ID 格式无效');
        }

        $client = new Client([
            'timeout' => 12,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'Referer' => 'https://m.douban.com/movie/subject/' . $subjectId . '/',
                'User-Agent' => 'Mozilla/5.0 (compatible; FeiFeiCMS/8.0; +https://feifeicms.local)',
            ],
        ]);
        $response = $client->get('https://m.douban.com/rexxar/api/v2/movie/' . $subjectId);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('豆瓣返回状态 ' . $response->getStatusCode());
        }
        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload) || empty($payload['title'])) {
            throw new RuntimeException('豆瓣没有返回有效影片资料');
        }

        return $this->mapPayload($subjectId, $payload);
    }

    public function mapPayload(string $subjectId, array $payload): array
    {
        $names = static fn (mixed $rows): string => implode(',', array_values(array_filter(array_map(
            static fn (mixed $row): string => is_array($row) ? trim((string) ($row['name'] ?? '')) : trim((string) $row),
            is_array($rows) ? $rows : []
        ))));
        $strings = static fn (mixed $rows): string => implode(',', array_values(array_filter(array_map('strval', is_array($rows) ? $rows : []))));
        $rating = is_array($payload['rating'] ?? null) ? $payload['rating'] : [];
        $poster = is_array($payload['pic'] ?? null) ? (string) (($payload['pic']['large'] ?? $payload['pic']['normal'] ?? '')) : (string) ($payload['cover_url'] ?? '');
        $releaseDate = '';
        foreach (array_merge((array) ($payload['release_date'] ?? []), (array) ($payload['pubdate'] ?? [])) as $date) {
            if (preg_match('/\d{4}-\d{2}-\d{2}/', (string) $date, $match)) { $releaseDate = $match[0]; break; }
        }
        $length = 0;
        if (preg_match('/(\d+)\s*分钟/u', (string) (($payload['durations'][0] ?? '')), $match)) $length = (int) $match[1] * 60;
        $tags = [];
        foreach ((array) ($payload['tags'] ?? []) as $tag) {
            $value = is_array($tag) ? (string) ($tag['name'] ?? '') : (string) $tag;
            if ($value !== '') $tags[] = $value;
        }
        $subtitle = array_values(array_filter(array_merge([(string) ($payload['original_title'] ?? '')], array_map('strval', (array) ($payload['aka'] ?? [])))));
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
        $imdb = trim((string) ($payload['imdb_id'] ?? $payload['imdb'] ?? $extra['imdb'] ?? ''));
        if ($imdb !== '' && preg_match('/tt\d{5,12}/i', $imdb, $match)) $imdb = strtolower($match[0]);
        else $imdb = '';

        return [
            'douban_id' => $subjectId,
            'title' => trim((string) ($payload['title'] ?? '')),
            'original_title' => trim((string) ($payload['original_title'] ?? '')),
            'subtitle' => implode(' / ', array_slice(array_unique($subtitle), 0, 8)),
            'poster_url' => $poster,
            'release_year' => (int) ($payload['year'] ?? 0),
            'release_date' => $releaseDate,
            'area' => $strings($payload['countries'] ?? []),
            'language' => $strings($payload['languages'] ?? []),
            'type' => $strings($payload['genres'] ?? []),
            'tags' => implode(',', array_slice(array_unique($tags), 0, 20)),
            'director' => $names($payload['directors'] ?? []),
            'actor' => $names($payload['actors'] ?? []),
            'writer' => $names($payload['writers'] ?? $payload['screenwriters'] ?? []),
            'content' => trim((string) ($payload['intro'] ?? '')),
            'imdb_id' => $imdb,
            'douban_score' => (float) ($rating['value'] ?? 0),
            'rating' => (float) ($rating['value'] ?? 0),
            'rating_count' => (int) ($rating['count'] ?? 0),
            'length' => $length,
            'episode_total' => max(0, (int) ($payload['episodes_count'] ?? 0)),
            'episode_label' => trim((string) ($payload['episodes_info'] ?? '')),
            'source_ref' => '[douban]=[' . $subjectId . ']',
        ];
    }
}
