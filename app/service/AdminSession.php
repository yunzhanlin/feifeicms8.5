<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;
use think\facade\Session;

/** Credential changes and disable/reactivate cycles revoke administrator cookies. */
final class AdminSession
{
    public static function establish(array $admin): void
    {
        Session::regenerate(true);
        Session::set('admin_id', (int) $admin['id']);
        Session::set('admin_name', (string) $admin['username']);
        Session::set('admin_session_version', (int) $admin['session_version']);
        Session::set('admin_credential', hash('sha256', (string) $admin['password_hash']));
    }

    public static function id(): int
    {
        $id = (int) Session::get('admin_id', 0);
        if ($id < 1) return 0;
        $admin = Db::table('ffx_admins')->where('id', $id)->where('status', 'active')
            ->field('id,password_hash,session_version')->find();
        if ($admin !== null
            && (int) Session::get('admin_session_version', 0) === (int) $admin['session_version']
            && hash_equals(hash('sha256', (string) $admin['password_hash']), (string) Session::get('admin_credential', ''))) return $id;
        foreach (['admin_id', 'admin_name', 'admin_session_version', 'admin_credential', 'update_notice_pending'] as $key) Session::delete($key);
        return 0;
    }
}
