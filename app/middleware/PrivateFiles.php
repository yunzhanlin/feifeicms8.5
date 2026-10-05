<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\PublicPathPolicy;
use Closure;
use think\Request;
use think\Response;

final class PrivateFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        $uri = (string) $request->server('REQUEST_URI', '');
        if (PublicPathPolicy::isPrivate($request->pathinfo()) || PublicPathPolicy::isPrivate($uri)) {
            return response('页面不存在', 404)->header(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        return $next($request);
    }
}
