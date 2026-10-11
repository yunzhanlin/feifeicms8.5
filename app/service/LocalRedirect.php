<?php
declare(strict_types=1);
namespace app\service;

final class LocalRedirect
{
    public static function allow(string $target, string $fallback = '/user/center'): string
    {
        $target = trim($target);
        if (strlen($target) > 1000) return $fallback;
        $decoded = $target;
        for ($pass = 0; $pass < 5; ++$pass) {
            if (!str_starts_with($decoded, '/') || str_starts_with($decoded, '//') || preg_match('/[\\\\\x00-\x20\x7f]/', $decoded)) return $fallback;
            $next = rawurldecode($decoded);
            if ($next === $decoded) return $target;
            $decoded = $next;
        }
        return $fallback;
    }
}
