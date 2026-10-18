<?php
declare(strict_types=1);

namespace app\service;

use GuzzleHttp\Client;

final class CollectionHttpClient
{
    public function __construct(
        private readonly SafeRemoteUrl $safeUrl,
        private readonly SiteSettings $settings,
        private readonly CollectionResponseSizeGuard $sizeGuard,
        private readonly ?Client $client = null,
    ) {
    }

    /** @param array<string, int|string> $params @return array<string, mixed> */
    public function fetch(string $endpoint, array $params): array
    {
        $resolved = $this->safeUrl->resolve($endpoint);
        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $url = $endpoint . $separator . http_build_query($params);
        $timeout = $this->settings->int('admin.collection.timeout', 15, 2, 120);
        $client = $this->client ?? new Client();
        $options = [
            'timeout' => $timeout, 'connect_timeout' => min(10, $timeout),
            'http_errors' => false, 'allow_redirects' => false, 'decode_content' => true,
            'headers' => ['Accept' => 'application/json', 'User-Agent' => $this->settings->string('admin.collection.user_agent', 'FeiFeiCMS/8')],
            'curl' => [CURLOPT_RESOLVE => [$resolved['host'] . ':' . $resolved['port'] . ':' . $resolved['ip']]],
            'on_headers' => function ($response): void {
                $this->sizeGuard->assertContentLength((int) $response->getHeaderLine('Content-Length'));
            },
            'progress' => $this->sizeGuard->progress(...),
        ];
        $attempts = $this->settings->int('admin.collection.retry_count', 2, 0, 5) + 1;
        $last = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // cURL writes decoded bytes into this sink. Give every retry its
            // own budget and close failed responses instead of accumulating them.
            $sink = $this->sizeGuard->sink();
            try {
                $response = $client->get($url, $options + ['sink' => $sink]);
                $last = null;
                break;
            } catch (\Throwable $exception) {
                $sink->close();
                $last = $exception;
                if ($attempt < $attempts) usleep(200000 * $attempt);
            }
        }
        if ($last !== null || !isset($response)) throw new \RuntimeException('采集请求失败：' . ($last?->getMessage() ?? '未知错误'), 0, $last);
        try {
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('采集源返回 HTTP ' . $response->getStatusCode());
            }
            $body = (string) $response->getBody();
            $this->sizeGuard->assertBody($body);
            $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('采集响应不是 JSON 对象');
            }
            return $decoded;
        } finally {
            $response->getBody()->close();
            $sink->close();
        }
    }
}
