<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Session;

final class CsrfToken
{
    private const KEY = 'csrf_token';

    public function get(): string
    {
        $token = (string) Session::get(self::KEY, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::KEY, $token);
        }
        return $token;
    }

    public function verify(?string $token): bool
    {
        $stored = (string) Session::get(self::KEY, '');
        return $stored !== '' && is_string($token) && hash_equals($stored, $token);
    }
}
