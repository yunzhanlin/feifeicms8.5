<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use Meilisearch\Client;

/**
 * Builds Meilisearch clients from the effective application configuration.
 *
 * Admin settings are optional overrides. An empty override must not shadow a
 * valid value from .env/config, otherwise the SDK receives an empty URI and
 * fails before it can make a request.
 */
final class MeilisearchClientFactory
{
    public function __construct(private readonly SiteSettings $settings)
    {
    }

    public function client(): Client
    {
        return new Client($this->host(), $this->key());
    }

    public function host(): string
    {
        $defaults = (array) config('feifei.search.meilisearch');
        $host = trim($this->settings->string('admin.cache.search_host', ''));
        if ($host === '') {
            $host = trim((string) ($defaults['host'] ?? ''));
        }
        if ($host === '') {
            $host = 'http://127.0.0.1:7700';
        }

        // Accept the common host:port form in the admin panel, while always
        // passing an absolute URI to the Meilisearch SDK.
        if (str_starts_with($host, '//')) {
            $host = 'http:' . $host;
        } elseif (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $host)) {
            $host = 'http://' . $host;
        }

        $parts = parse_url($host);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('搜索服务地址无效，请填写完整地址，例如 http://127.0.0.1:7700');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (($parts['host'] ?? '') === '' || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('搜索服务地址无效，请填写完整地址，例如 http://127.0.0.1:7700');
        }

        return rtrim($host, '/');
    }

    public function key(): string
    {
        $defaults = (array) config('feifei.search.meilisearch');
        $key = trim($this->settings->string('admin.cache.search_key', ''));
        return $key !== '' ? $key : trim((string) ($defaults['key'] ?? ''));
    }

    public function index(): string
    {
        $defaults = (array) config('feifei.search.meilisearch');
        $index = trim($this->settings->string('admin.cache.search_index', ''));
        return $index !== '' ? $index : trim((string) ($defaults['index'] ?? 'feifeicms_media'));
    }
}
