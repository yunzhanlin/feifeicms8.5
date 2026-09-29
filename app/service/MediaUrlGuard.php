<?php
declare(strict_types=1);

namespace app\service;

final class MediaUrlGuard
{
    public function allow(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'])) {
            return null;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
