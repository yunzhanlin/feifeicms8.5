<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class TmdbMetadata
{
    public const KEY_SETTING = 'admin.collection.tmdb_api_key';
    private const API = 'https://api.themoviedb.org/3';
    private const SITE = 'https://www.themoviedb.org';

    public function __construct(private readonly PublicJsonClient $http, private readonly SiteSettings $settings) {}

    public function search(string $title, string $type): array
    {
        $result = $this->get('/search/' . $type, ['query' => $title, 'include_adult' => 'false']);
        return array_slice($this->matches($result['results'] ?? [], $type), 0, 15);
    }

    public function searchAll(string $title): array
    {
        $result = $this->get('/search/multi', ['query' => $title, 'include_adult' => 'false']);
        return $this->matches($result['results'] ?? []);
    }

    public function findImdb(string $id): array
    {
        $result = $this->get('/find/' . $id, ['external_source' => 'imdb_id']);
        return array_merge($this->matches($result['tv_results'] ?? [], 'tv'), $this->matches($result['movie_results'] ?? [], 'movie'));
    }

    private function matches(array $rows, ?string $type = null): array
    {
        $matches = [];
        foreach ($rows as $row) {
            $kind = $type ?? ($row['media_type'] ?? '');
            if (!in_array($kind, ['tv', 'movie'], true) || empty($row['id'])) continue;
            $title = (string) ($row['name'] ?? $row['title'] ?? '');
            $name = (string) ($row['original_name'] ?? $row['original_title'] ?? '');
            $date = (string) ($row['first_air_date'] ?? $row['release_date'] ?? '');
            $matches[] = ['id' => (string) $row['id'], 'title' => $title,
                'detail' => trim($date . ' ' . ($name !== $title ? $name : '')),
                'url' => self::SITE . '/' . $kind . '/' . (int) $row['id'],
                'original_title' => $name, 'year' => (int) substr($date, 0, 4), 'type' => $kind];
        }
        return $matches;
    }

    public function preview(string $id, string $type, int $season, bool $plots, bool $cast): array
    {
        $path = '/' . $type . '/' . $id;
        $series = $this->get($path, $type === 'movie' && $cast ? ['append_to_response' => 'credits'] : []);
        $title = trim((string) ($series['name'] ?? $series['title'] ?? ''));
        if ($title === '') throw new RuntimeException('TMDB 没有返回有效的剧目资料');

        $url = self::SITE . $path;
        $episodes = $actors = $seasons = [];
        if ($type === 'tv') {
            foreach ($series['seasons'] ?? [] as $row) {
                $seasons[] = ['number' => (int) $row['season_number'], 'name' => (string) $row['name'], 'count' => (int) $row['episode_count']];
            }
            if ($season === -1) {
                $regular = array_values(array_filter($seasons, static fn (array $row): bool => $row['number'] > 0));
                if (count($regular) === 1) $season = $regular[0]['number'];
            }
            if (!in_array($season, array_column($seasons, 'number'), true)) {
                return ['title' => $title, 'url' => $url, 'episodes' => [], 'cast' => [], 'seasons' => $seasons, 'season_found' => false];
            }
            $path .= '/season/' . $season;
            $url .= '/season/' . $season;
            $data = $this->get($path, $cast ? ['append_to_response' => 'aggregate_credits'] : []);
            if ($plots) {
                $fallback = [];
                foreach ($data['episodes'] ?? [] as $row) {
                    if (trim((string) ($row['overview'] ?? '')) === '') {
                        $fallback = $this->get($path, ['language' => 'en-US']);
                        $fallback = array_column($fallback['episodes'] ?? [], null, 'episode_number');
                        break;
                    }
                }
                foreach ($data['episodes'] ?? [] as $row) {
                    $number = (int) ($row['episode_number'] ?? 0);
                    $content = trim((string) ($row['overview'] ?? ''));
                    if ($content === '') $content = trim((string) ($fallback[$number]['overview'] ?? ''));
                    if ($number < 1 || $content === '') continue;
                    $episodes[] = ['episode_no' => $number, 'title' => trim((string) ($row['name'] ?? '')),
                        'content' => $content, 'url' => $url . '/episode/' . $number];
                }
            }
            $credits = $data['aggregate_credits']['cast'] ?? [];
            if ($cast && !array_key_exists('aggregate_credits', $data)) {
                $credits = $this->get($path . '/aggregate_credits')['cast'] ?? [];
            }
            if ($cast && $credits === []) {
                $credits = $this->get('/tv/' . $id . '/aggregate_credits')['cast'] ?? [];
            }
        } else {
            $credits = $series['credits']['cast'] ?? [];
            if ($cast && !array_key_exists('credits', $series)) $credits = $this->get($path . '/credits')['cast'] ?? [];
        }

        if ($cast) {
            foreach ($credits as $row) {
                $name = trim((string) ($row['name'] ?? $row['original_name'] ?? ''));
                $personId = (int) ($row['id'] ?? 0);
                if ($name === '' || $personId < 1) continue;
                $roles = array_column($row['roles'] ?? [], 'character');
                if ($roles === []) $roles = [(string) ($row['character'] ?? '')];
                $photo = (string) ($row['profile_path'] ?? '');
                foreach (array_unique($roles) as $role) {
                    $actors[] = ['id' => (string) $personId, 'name' => $name, 'character' => trim((string) $role),
                        'photo' => preg_match('~^/[a-zA-Z0-9_.-]+$~D', $photo) ? 'https://image.tmdb.org/t/p/w342' . $photo : '',
                        'url' => self::SITE . '/person/' . $personId, 'character_url' => '', 'character_bio' => ''];
                }
            }
        }
        return ['title' => $title, 'url' => $url, 'episodes' => $episodes, 'cast' => $actors, 'seasons' => $seasons, 'season_found' => true];
    }

    /** @return array<string, string> */
    public function profile(string $id): array
    {
        if (!preg_match('/^[1-9]\d{0,9}$/D', $id)) throw new RuntimeException('TMDB 人物 ID 无效');
        $path = '/person/' . $id;
        $data = $this->get($path);
        if ((string) ($data['id'] ?? '') !== $id) throw new RuntimeException('TMDB 未返回匹配的演员档案');
        $bio = trim((string) ($data['biography'] ?? ''));
        if ($bio === '') $bio = trim((string) ($this->get($path, ['language' => 'en-US'])['biography'] ?? ''));
        $department = (string) ($data['known_for_department'] ?? '');
        $photo = (string) ($data['profile_path'] ?? '');
        return [
            'aliases' => implode('、', array_unique($data['also_known_as'] ?? [])),
            'gender' => [1 => '女', 2 => '男', 3 => '非二元性别'][(int) ($data['gender'] ?? 0)] ?? '',
            'birthday' => (string) ($data['birthday'] ?? ''),
            'profession' => ['Acting' => '演员', 'Directing' => '导演', 'Writing' => '编剧'][$department] ?? $department,
            'birthplace' => (string) ($data['place_of_birth'] ?? ''),
            'avatar_url' => preg_match('~^/[a-zA-Z0-9_.-]+$~D', $photo) ? 'https://image.tmdb.org/t/p/w342' . $photo : '',
            'biography' => $bio,
            'source_url' => self::SITE . $path,
        ];
    }

    private function get(string $path, array $query = []): array
    {
        $key = trim((string) env('TMDB_API_KEY', ''));
        $token = trim((string) env('TMDB_READ_ACCESS_TOKEN', ''));
        if ($token === '' && $key === '') $key = trim($this->settings->string(self::KEY_SETTING));
        if (!preg_match('/^[A-Za-z0-9._-]{16,2048}$/D', $token !== '' ? $token : $key)) {
            throw new RuntimeException('请先在后台采集设置填写 TMDB API 密钥或 API 读取访问令牌');
        }
        if ($token === '' && str_contains($key, '.')) $token = $key;
        $headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
        if ($token === '') $query['api_key'] = $key;
        try {
            return $this->http->get(self::API . $path, $query + ['language' => 'zh-CN'], $headers, 7);
        } catch (RuntimeException $error) {
            if (preg_match('/HTTP (\d+)$/D', $error->getMessage(), $status)) {
                throw new RuntimeException('TMDB 返回 HTTP ' . $status[1], 0, $error);
            }
            throw new RuntimeException('无法读取 TMDB，检查 API 配置和网络后重试', 0, $error);
        } catch (\Throwable $error) {
            throw new RuntimeException('无法读取 TMDB，检查 API 配置和网络后重试', 0, $error);
        }
    }
}
