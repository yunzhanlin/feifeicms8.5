<?php
declare(strict_types=1);
namespace app\service;

final class RemoteDanmaku
{
    public function __construct(private readonly SiteSettings $settings, private readonly PublicJsonClient $http, private readonly ResilientCache $cache) {}

    public function fetch(string $title, int $episode): array
    {
        if (!$this->settings->bool('admin.player.player_remote_danmu_enabled')) return [];
        $endpoint = trim($this->settings->string('admin.player.player_remote_danmu_api'));
        if ($endpoint === '') throw new \RuntimeException('未配置远程弹幕 API');
        $token = trim($this->settings->string('admin.player.player_remote_danmu_token'));
        if (strlen($token) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $token)) throw new \RuntimeException('远程弹幕 Token 格式无效');
        $timeout = $this->settings->int('admin.player.player_remote_danmu_timeout', 15, 1, 30);
        $maximum = $this->settings->int('admin.player.player_remote_danmu_max', 20000, 1, 20000);
        $headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
        $identity = hash('sha256', json_encode([$endpoint, $token, $title, $episode, $maximum]));
        return $this->cache->remember('danmaku:remote:' . $identity, $this->settings->int('admin.player.player_remote_danmu_cache_time', 21600, 1, 604800), function () use ($endpoint, $headers, $timeout, $maximum, $identity, $title, $episode): array {
            $matchKey = 'danmaku:match:' . $identity;
            $matched = $this->cache->get($matchKey);
            if (is_string($matched) && PlayerResolver::origin($matched) === PlayerResolver::origin($endpoint)) $payload = $this->http->get($matched, [], $headers, $timeout);
            else {
                $payload = $this->http->get($endpoint, ['title' => mb_substr($title, 0, 255), 'episode' => max(1, $episode)], $headers, $timeout);
                PublicJsonClient::assertSuccess($payload);
                $match = $payload['danmaku_url'] ?? ($payload['data']['danmaku_url'] ?? null);
                if (is_string($match)) {
                    if (PlayerResolver::origin($match) !== PlayerResolver::origin($endpoint)) throw new \RuntimeException('远程弹幕匹配地址越过 API 同源边界');
                    $payload = $this->http->get($match, [], $headers, $timeout);
                    $this->cache->set($matchKey, $match, $this->settings->int('admin.player.player_remote_danmu_match_cache_time', 604800, 1, 2592000));
                }
            }
            PublicJsonClient::assertSuccess($payload);
            $rows = $payload['data']['danmaku'] ?? $payload['data']['danmuku'] ?? $payload['danmaku'] ?? $payload['danmuku'] ?? $payload['data'] ?? (array_is_list($payload) ? $payload : null);
            if (!is_array($rows) || !array_is_list($rows)) throw new \RuntimeException('远程弹幕响应缺少弹幕数组');
            return self::normalize($rows, $maximum);
        });
    }

    public static function normalize(array $rows, int $maximum): array
    {
        $output = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $tuple = array_is_list($row);
            $rawText = $tuple ? ($row[4] ?? $row[3] ?? '') : ($row['text'] ?? '');
            $rawTime = $tuple ? ($row[0] ?? 0) : ($row['time'] ?? 0);
            $rawMode = $tuple ? ($row[1] ?? 0) : ($row['mode'] ?? 0);
            $rawColor = $tuple ? ($row[2] ?? '#FFFFFF') : ($row['color'] ?? '#FFFFFF');
            if (!is_string($rawText) || !is_numeric($rawTime) || !is_scalar($rawMode) || !is_scalar($rawColor)) continue;
            $text = trim($rawText);
            $time = (float) $rawTime;
            if ($text === '' || !is_finite($time) || $time < 0 || $time > 86400) continue;
            $mode = (int) $rawMode;
            $color = strtoupper((string) $rawColor);
            if (ctype_digit($color) && (int) $color <= 16777215) $color = sprintf('#%06X', (int) $color);
            $output[] = ['text' => mb_substr($text, 0, 200), 'time' => $time, 'mode' => in_array($mode, [0, 1, 2], true) ? $mode : 0, 'color' => preg_match('/^#[0-9A-F]{6}$/D', $color) ? $color : '#FFFFFF', 'border' => false];
            if (count($output) >= max(1, min(20000, $maximum))) break;
        }
        return $output;
    }
}
