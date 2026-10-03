<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\facade\Session;
use think\facade\Db;
use think\Request;
use think\Response;

final class AdminGuard
{
    public function __construct(private readonly \app\service\AdminAuthorization $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = (int) Session::get('admin_id', 0);
        if ($adminId < 1) {
            return redirect('/admin.php');
        }
        if (Db::table('ffx_admins')->where('id', $adminId)->where('status', 'active')->count() !== 1) {
            Session::clear();
            return redirect('/admin.php');
        }
        if (!$this->authorization->allows($adminId, $request->controller())) {
            return response('没有权限执行此操作，请联系超级管理员分配角色', 403);
        }
        return $next($request);
    }
}
