<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Resolve permissions on every request so revocations take effect immediately. */
final class AdminAuthorization
{
    public function allows(int $adminId, string $controller): bool
    {
        $roles = Db::table('ffx_admin_roles')->alias('ar')->join(['ffx_roles' => 'r'], 'r.id=ar.role_id')
            ->where('ar.admin_id', $adminId)->column('r.role_key');
        if (in_array('super_admin', $roles, true)) return true;
        $permission = self::permissionFor($controller);
        if ($permission === null) return false;
        return Db::table('ffx_admin_roles')->alias('ar')
            ->join(['ffx_role_permissions' => 'rp'], 'rp.role_id=ar.role_id')
            ->join(['ffx_permissions' => 'p'], 'p.id=rp.permission_id')
            ->where('ar.admin_id', $adminId)->where('p.permission_key', $permission)->count() > 0;
    }

    public static function permissionFor(string $controller): ?string
    {
        $parts = preg_split('/[.\\\\\/]/', strtolower($controller));
        return match (end($parts)) {
            'dashboard', 'login' => 'dashboard.view',
            'vod', 'vodtools', 'playback', 'scenarios', 'tags' => 'media.manage',
            'categories' => 'category.manage',
            'content' => 'content.manage',
            'users', 'comments', 'danmaku' => 'user.manage',
            'collections', 'crontab' => 'collection.manage',
            // Editing templates can execute PHP; restoring a database can change
            // credentials. These are deliberately super-admin-only operations.
            'administrators', 'database', 'legacyupgrade', 'tools', 'updater' => null,
            'settings', 'system', 'operations', 'billing' => 'system.manage',
            default => null,
        };
    }
}
