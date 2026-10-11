<?php
declare(strict_types=1);

namespace app\service;

final class PasswordHasher
{
    public const MAX_BYTES = 200;
    private const PREFIX = 'ffx-sha256-bcrypt:';

    public function verify(string $stored, string $plain): bool
    {
        if (strlen($plain) > self::MAX_BYTES) return false;
        if (str_starts_with($stored, self::PREFIX)) return password_verify($this->digest($plain), substr($stored, strlen(self::PREFIX)));
        if ($this->isLegacyMd5($stored)) {
            return hash_equals(strtolower($stored), md5($plain));
        }
        // Legacy bcrypt did not record any suffix after byte 72. Longer inputs
        // cannot prove that suffix and require a password reset instead.
        if (str_starts_with($stored, '$2') && strlen($plain) > 72) return false;
        return password_verify($plain, $stored);
    }

    public function needsRehash(string $stored): bool
    {
        if (!str_starts_with($stored, self::PREFIX)) return true;
        return password_needs_rehash(substr($stored, strlen(self::PREFIX)), PASSWORD_BCRYPT);
    }

    public function hash(string $plain): string
    {
        if (strlen($plain) > self::MAX_BYTES) throw new \InvalidArgumentException('密码不得超过 200 字节');
        return self::PREFIX . password_hash($this->digest($plain), PASSWORD_BCRYPT);
    }

    private function digest(string $plain): string
    {
        // Fixed-size digest includes the full password on hosts without Argon2.
        return base64_encode(hash('sha256', "FeiFeiCMS password v1\0" . $plain, true));
    }

    private function isLegacyMd5(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    }
}
