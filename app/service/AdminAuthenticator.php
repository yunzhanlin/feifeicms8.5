<?php
declare(strict_types=1);

namespace app\service;

use app\model\Admin;
use think\facade\Db;

final class AdminAuthenticator
{
    public function __construct(private readonly PasswordHasher $passwordHasher)
    {
    }

    public function attempt(string $username, string $password, string $ip): ?Admin
    {
        $admin = Admin::where('username', $username)->where('status', 'active')->find();
        if ($admin === null || !$this->passwordHasher->verify((string) $admin->password_hash, $password)) {
            return null;
        }

        $verifiedHash = (string) $admin->password_hash;
        $replacement = $this->passwordHasher->needsRehash($verifiedHash) ? $this->passwordHasher->hash($password) : null;
        // Password hashing is deliberately outside the short row lock. Recheck
        // before writing so a concurrent reset cannot be overwritten by rehash.
        return Db::transaction(function () use ($admin, $verifiedHash, $replacement, $ip): ?Admin {
            $current = Admin::where('id', (int) $admin->id)->lock(true)->find();
            if ($current === null || $current->status !== 'active'
                || (int) $current->session_version !== (int) $admin->session_version
                || !hash_equals($verifiedHash, (string) $current->password_hash)) return null;
            if ($replacement !== null) $current->password_hash = $replacement;
            $current->login_count = (int) $current->login_count + 1;
            $current->last_login_ip = mb_substr($ip, 0, 45);
            $current->last_login_at = gmdate('Y-m-d H:i:s.u');
            $current->save();
            return $current;
        });
    }
}
