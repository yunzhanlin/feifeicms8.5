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
        $matches = [];
        foreach (array_slice($result['results'] ?? [], 0, 15) as $row) {
            if (empty($row['id'])) continue;
            $title = (string) ($row['name'] ?? $row['title'] ?? '');
            $name = (string) ($row['original_name'] ?? $row['original_title'] ?? '');
            $date = (string) ($row['first_air_date'] ?? $row['release_date'] ?? '');
            $matches[] = ['id' => (string) $row['id'], 'title' => $title,
                'detail' => trim($date . ' ' . ($name !== $title ? $name : '')),
                'url' => self::SITE . '/' . $type . '/' . (int) $row['id']];
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
