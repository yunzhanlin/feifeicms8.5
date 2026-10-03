<?php
declare(strict_types=1);

namespace app\service;

final class MySqlCompatibility
{
    public static function supports(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false || !preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return false;
        }

        $major = (int) $matches[1];
        $minor = (int) $matches[2];
        $patch = (int) $matches[3];

        return $major === 8 || ($major === 5 && $minor === 7 && $patch >= 13);
    }

    public static function requirement(): string
    {
        return 'MySQL 5.7.13+ 或 8.x（不含 MariaDB）';
    }
}
