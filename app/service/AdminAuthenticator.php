<?php
declare(strict_types=1);

namespace app\service;

use app\model\Admin;

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

        if ($this->passwordHasher->needsRehash((string) $admin->password_hash)) {
            $admin->password_hash = $this->passwordHasher->hash($password);
        }
        $admin->login_count = (int) $admin->login_count + 1;
        $admin->last_login_ip = mb_substr($ip, 0, 45);
        $admin->last_login_at = gmdate('Y-m-d H:i:s.u');
        $admin->save();

        return $admin;
    }
}
