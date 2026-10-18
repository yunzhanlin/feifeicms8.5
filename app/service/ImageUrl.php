<?php
declare(strict_types=1);

namespace app\service;

/** Presentation-only rewriting: never persist proxy URLs or fetch remote images here. */
final class ImageUrl
{
    public function __construct(private readonly SiteSettings $settings) {}

    public function display(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 8192 || preg_match('/[\x00-\x1F\x7F\\\\]/', $url)) return '';
        $absolute = str_starts_with($url, '//') ? 'https:' . $url : $url;
        $parts = parse_url($absolute);
        if (!is_array($parts)) return '';
        if (!isset($parts['scheme'])) return $url; // Local uploads and theme assets.
        if (!$this->isHttp($parts)) return '';

        $proxy = trim($this->settings->string('admin.files.upload_referer'));
        $proxyParts = parse_url($proxy);
        if ($proxy === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $proxy)
            || !is_array($proxyParts) || !$this->isHttp($proxyParts) || isset($proxyParts['fragment'])) return $url;

        // Already formatted values may pass through a template helper again.
        $prefix = explode('{url}', $proxy, 2)[0];
        if (str_starts_with($absolute, $prefix)) return $url;

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $domains = preg_split('/[\s,;|，；]+/u', $this->settings->string('admin.files.upload_referer_domains'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if (str_contains($domain, '://')) $domain = (string) parse_url($domain, PHP_URL_HOST);
            $domain = rtrim(ltrim($domain, '*.'), '.');
            if ($domain === '' || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/D', $domain)) continue;
            if ($host !== $domain && !str_ends_with($host, '.' . $domain)) continue;
            $encoded = rawurlencode($absolute);
            return str_contains($proxy, '{url}') ? str_replace('{url}', $encoded, $proxy) : $proxy . $encoded;
        }
        return $url;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function fields(array $row): array
    {
        foreach (['poster_url', 'backdrop_url', 'cover_url', 'banner_url', 'logo_url', 'avatar_url', 'image_url', 'mobile_image_url', 'vod_pic', 'news_pic', 'person_pic'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) $row[$field] = $this->display($row[$field]);
        }
        return $row;
    }

    /** @param array<string, mixed> $parts */
    private function isHttp(array $parts): bool
    {
        return isset($parts['scheme'], $parts['host']) && $parts['host'] !== ''
            && in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            && !isset($parts['user']) && !isset($parts['pass']);
    }
}
