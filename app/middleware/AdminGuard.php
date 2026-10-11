<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

final class AdminGuard
{
    public function __construct(private readonly \app\service\AdminAuthorization $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $adminId = \app\service\AdminSession::id();
        if ($adminId < 1) {
            return redirect('/admin.php');
        }
        if (!$this->authorization->allows($adminId, $request->controller())) {
            return response('没有权限执行此操作，请联系超级管理员分配角色', 403);
        }
        return $next($request);
    }
}
