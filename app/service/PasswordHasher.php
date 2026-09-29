<?php
declare(strict_types=1);

namespace app\service;

final class PasswordHasher
{
    public function verify(string $stored, string $plain): bool
    {
        if ($this->isLegacyMd5($stored)) {
            return hash_equals(strtolower($stored), md5($plain));
        }
        return password_verify($plain, $stored);
    }

    public function needsRehash(string $stored): bool
    {
        return $this->isLegacyMd5($stored) || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    private function isLegacyMd5(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    }
}
