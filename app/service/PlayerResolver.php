<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

final class PlayerResolver
{
    public const DEFAULT_CONFIG = ['parser_mode' => 'json', 'direct_type' => 'm3u8'];

    public function __construct(private readonly SiteSettings $settings, private readonly PublicJsonClient $http, private readonly MediaUrlGuard $guard, private readonly SafeRemoteUrl $safeUrl) {}

    public function plan(string $key): array
    {
        $player = Db::table('ffx_players')->where('player_key', PlaybackPlayerRegistry::normalizeKey($key))->where('status', 'enabled')->find();
        if ($player === null) throw new \RuntimeException('播放器未注册或已停用');
        $config = is_array($player['config'] ?? null) ? $player['config'] : (json_decode((string) ($player['config'] ?? ''), true) ?: []);
        // Each registered player decides its own mode. A global parser must not
        // silently take over a player whose parser URL was intentionally left blank.
        $endpoint = trim((string) ($player['parser_url'] ?? ''));
        $mode = (string) ($config['parser_mode'] ?? $this->settings->string('admin.player.parser_mode', 'json'));
        if (!in_array($mode, ['json', 'iframe'], true)) throw new \RuntimeException('解析模式无效');
        $type = strtolower((string) ($config['direct_type'] ?? self::DEFAULT_CONFIG['direct_type']));
        if (!in_array($type, ['', 'm3u8', 'mp4', 'webm', 'ogg'], true)) throw new \RuntimeException('直链媒体类型无效');
        return ['endpoint' => $endpoint, 'mode' => $mode, 'direct_type' => $type, 'force' => $endpoint !== ''];
    }

    public function resolve(string $key, string $url): array
    {
        // A parser input can be an opaque resource ID, not just an HTTP URL.
        // Keep the original bytes; only the parser endpoint and its output are URLs.
        if (trim($url) === '' || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url)) throw new \RuntimeException('播放地址无效');
        $plan = $this->plan($key);
        if ($plan['endpoint'] === '') {
            if ($this->guard->allow($url) === null) throw new \RuntimeException('该播放器未配置解析接口，播放地址必须是 HTTP/HTTPS 直链');
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            $type = preg_match('/\.(m3u8|mp4|webm|ogg)$/', $path, $match) ? $match[1] : ($plan['direct_type'] ?: 'm3u8');
            return ['url' => $url, 'type' => $type];
        }
        $this->assertEndpoint($plan['endpoint']);
        if ($plan['mode'] === 'iframe') {
            $this->safeUrl->resolve($plan['endpoint']);
            return ['url' => $this->appendUrl($plan['endpoint'], $url), 'type' => 'iframe'];
        }
        $endpoint = str_replace('{url}', rawurlencode($url), $plan['endpoint']);
        $payload = $this->http->get($endpoint, str_contains($plan['endpoint'], '{url}') ? [] : ['url' => $url], [], 10, 1048576);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        PublicJsonClient::assertSuccess($payload);
        $resolved = $this->guard->allow((string) ($data['url'] ?? '')) ?? throw new \RuntimeException('解析接口未返回有效播放地址');
        $type = is_string($data['type'] ?? null) ? strtolower(trim($data['type'])) : '';
        $type = match ($type) {
            'hls', 'application/x-mpegurl', 'application/vnd.apple.mpegurl' => 'm3u8',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg', 'application/ogg' => 'ogg',
            default => $type,
        };
        // Some providers use "type" for a transport label, e.g. 单线程.
        // Recognized media types remain authoritative, including extensionless URLs.
        if (!in_array($type, ['m3u8', 'mp4', 'webm', 'ogg'], true)) {
            $type = strtolower(pathinfo((string) parse_url($resolved, PHP_URL_PATH), PATHINFO_EXTENSION));
        }
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
