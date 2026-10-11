<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

final class PlayerResolver
{
    public function __construct(private readonly SiteSettings $settings, private readonly PublicJsonClient $http, private readonly MediaUrlGuard $guard, private readonly SafeRemoteUrl $safeUrl) {}

    public function plan(string $key): array
    {
        $player = Db::table('ffx_players')->where('player_key', PlaybackPlayerRegistry::normalizeKey($key))->where('status', 'enabled')->find();
        if ($player === null) throw new \RuntimeException('播放器未注册或已停用');
        $config = is_array($player['config'] ?? null) ? $player['config'] : (json_decode((string) ($player['config'] ?? ''), true) ?: []);
        $endpoint = trim((string) ($player['parser_url'] ?: $this->settings->string('admin.player.play_jiexi')));
        $mode = (string) ($config['parser_mode'] ?? $this->settings->string('admin.player.parser_mode', 'json'));
        if (!in_array($mode, ['json', 'iframe'], true)) throw new \RuntimeException('解析模式无效');
        $type = strtolower((string) ($config['direct_type'] ?? ''));
        if (!in_array($type, ['', 'm3u8', 'mp4', 'webm', 'ogg'], true)) throw new \RuntimeException('直链媒体类型无效');
        if ($endpoint !== '') $this->assertEndpoint($endpoint);
        return ['endpoint' => $endpoint, 'mode' => $mode, 'direct_type' => $type, 'force' => ($config['force_parser'] ?? false) === true];
    }

    public function resolve(string $key, string $url): array
    {
        $url = $this->guard->allow($url) ?? throw new \RuntimeException('播放地址无效');
        $plan = $this->plan($key);
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $type = preg_match('/\.(m3u8|mp4|webm|ogg)$/', $path, $match) ? $match[1] : $plan['direct_type'];
        if ($type !== '' && !$plan['force']) return ['url' => $url, 'type' => $type];
        if ($plan['endpoint'] === '') throw new \RuntimeException('请为该播放器配置解析接口或明确的直链媒体类型');
        if ($plan['mode'] === 'iframe') {
            $this->safeUrl->resolve($plan['endpoint']);
            return ['url' => $this->appendUrl($plan['endpoint'], $url), 'type' => 'iframe'];
        }
        $endpoint = str_replace('{url}', rawurlencode($url), $plan['endpoint']);
        $payload = $this->http->get($endpoint, str_contains($plan['endpoint'], '{url}') ? [] : ['url' => $url], [], 10, 1048576);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (isset($payload['code']) && !in_array((int) $payload['code'], [0, 200], true)) throw new \RuntimeException('解析服务返回失败状态');
        $resolved = $this->guard->allow((string) ($data['url'] ?? '')) ?? throw new \RuntimeException('解析接口未返回有效播放地址');
        $type = strtolower((string) ($data['type'] ?? pathinfo((string) parse_url($resolved, PHP_URL_PATH), PATHINFO_EXTENSION)));
        if (!in_array($type, ['m3u8', 'mp4', 'webm', 'ogg'], true)) throw new \RuntimeException('解析接口未返回受支持的媒体类型');
        return ['url' => $resolved, 'type' => $type];
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        return strtolower((string) ($parts['scheme'] ?? '')) . '://' . strtolower((string) ($parts['host'] ?? '')) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private function assertEndpoint(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map('strtolower', preg_split('/[\s,]+/', trim($this->settings->string('admin.player.parser_allowlist')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($this->guard->allow($url) === null || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/D', $host) || !in_array($host, $allowed, true)) throw new \RuntimeException('解析接口域名不在后台解析白名单中');
    }

    private function appendUrl(string $endpoint, string $url): string
    {
        if (str_contains($endpoint, '{url}')) return str_replace('{url}', rawurlencode($url), $endpoint);
        parse_str((string) parse_url($endpoint, PHP_URL_QUERY), $query);
        $query['url'] = $url;
        return explode('?', $endpoint, 2)[0] . '?' . http_build_query($query);
    }
}
