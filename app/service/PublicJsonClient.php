<?php
declare(strict_types=1);
namespace app\service;

use GuzzleHttp\Client;

/** Public HTTP only, pinned DNS, bounded body/time, and no redirects. */
final class PublicJsonClient
{
    public function __construct(private readonly SafeRemoteUrl $safeUrl, private readonly ?Client $client = null) {}

    public function get(string $url, array $query = [], array $headers = [], int $timeout = 10, int $maxBytes = 5242880): array
    {
        $resolved = $this->safeUrl->resolve($url);
        $guard = new CollectionResponseSizeGuard($maxBytes);
        $timeout = max(1, min(30, $timeout));
        $response = ($this->client ?? new Client())->get($url, [
            'query' => $query === [] ? (string) parse_url($url, PHP_URL_QUERY) : array_merge($this->query($url), $query),
            'headers' => $headers + ['Accept' => 'application/json', 'Accept-Encoding' => 'identity', 'User-Agent' => 'FeiFeiCMS/8.5'],
            'timeout' => $timeout, 'connect_timeout' => min(5, $timeout), 'http_errors' => false, 'allow_redirects' => false, 'decode_content' => false,
            'curl' => [CURLOPT_RESOLVE => [$resolved['host'] . ':' . $resolved['port'] . ':' . $resolved['ip']]],
            'on_headers' => static function ($response) use ($guard): void {
                $guard->assertContentLength((int) $response->getHeaderLine('Content-Length'));
                if (!in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)) throw new \RuntimeException('远程服务返回了不支持的压缩响应');
            },
            'progress' => $guard->progress(...),
        ]);
        if ($response->getStatusCode() !== 200) throw new \RuntimeException('远程服务返回 HTTP ' . $response->getStatusCode());
        $body = '';
        $stream = $response->getBody();
        while (!$stream->eof()) {
            $chunk = $stream->read(min(65536, $maxBytes + 1 - strlen($body)));
            if ($chunk === '') throw new \RuntimeException('远程响应读取中断');
            $body .= $chunk;
            $guard->assertBody($body);
        }
        $guard->assertBody($body);
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \RuntimeException('远程服务未返回 JSON 数据');
        return $decoded;
    }

    private function query(string $url): array { parse_str((string) parse_url($url, PHP_URL_QUERY), $query); return $query; }
}
