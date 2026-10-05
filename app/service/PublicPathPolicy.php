<?php
declare(strict_types=1);

namespace app\service;

/** Shared by the application middleware and PHP's development web router. */
final class PublicPathPolicy
{
    public static function isPrivate(string $path): bool
    {
        $path = explode('?', $path, 2)[0];
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) break;
            $path = $decoded;
        }
        $path = strtolower(str_replace('\\', '/', $path));
        if (str_contains($path, "\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) return true;
        if (preg_match('~(?:^|/)\.(?!well-known(?:/|$))~', $path)) return true;
        $path = ltrim($path, '/');
        // Reject the same paths via /index.php/ and accidental /public/ roots.
        $path = preg_replace('~^(?:(?:public|index\.php|admin\.php|api\.php|install\.php)/)+~', '', $path) ?? $path;
        // Only shipped front controllers are executable; uploaded/new PHP files
        // must not turn into a second unauthenticated application entry point.
        if (preg_match('~\.(?:php[0-9]?|phtml|phar)(?:/|$)~', $path)
            && !preg_match('~^(?:index|admin|api|install)\.php$~', $path)) return true;
        return preg_match('~^(?:view|tpl|template|templates|app|config|database|deploy|docker|docs|extend|plugins|route|runtime|scripts|tests|vendor|qa)(?:[/.]|$)~', $path) === 1
            || preg_match('~^(?:composer\.(?:json|lock|phar)|phpunit\.xml|think|Dockerfile|docker-compose\.ya?ml)(?:/|$)~i', $path) === 1
            || preg_match('~^legacy/(?:lib|tpl|runtime|uploads)(?:[/.]|$)~', $path) === 1
            || preg_match('~^(?:uploads|storage|static|mxstatic|player|legacy)/.*\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh)(?:/|$)~', $path) === 1;
    }
}
