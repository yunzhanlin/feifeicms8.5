<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;
use think\facade\Session;
use think\exception\HttpException;

final class UserSession
{
    public static function establish(array $user): void
    {
        Session::regenerate(true);
        Session::set('user_id', (int) $user['id']);
        Session::set('user_name', (string) $user['username']);
        Session::set('user_session_version', (int) $user['session_version']);
        Session::set('user_credential', hash('sha256', (string) $user['password_hash']));
    }

    public static function id(): int
    {
        $id = (int) Session::get('user_id', 0);
        if ($id < 1) return 0;
        $user = Db::table('ffx_users')->where('id', $id)->where('status', 'active')->whereNull('deleted_at')->field('id,password_hash,session_version')->find();
        if ($user === null || (int) Session::get('user_session_version', 0) !== (int) $user['session_version']
            || !hash_equals(hash('sha256', (string) $user['password_hash']), (string) Session::get('user_credential', ''))) {
            foreach (['user_id', 'user_name', 'user_session_version', 'user_credential'] as $key) Session::delete($key);
            throw new HttpException(401, '会员会话已失效，请重新登录');
        }
        return $id;
    }
}
