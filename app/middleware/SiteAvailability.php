<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\SiteSettings;
use Closure;
use think\Request;
use think\Response;

/** Applies the legacy site on/off switch without blocking admin and health tools. */
final class SiteAvailability
{
    public function __construct(private readonly SiteSettings $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $path = ltrim(strtolower($request->pathinfo()), '/');
        $entry = strtolower(basename((string) $request->baseFile()));
        $allowed = $entry === 'admin.php'
            || $path === 'health'
            || $path === 'install'
            || $path === 'install.php'
            || str_starts_with($path, 'admin')
            || str_starts_with($path, 'api/')
            || str_starts_with($path, 'static/')
            || str_starts_with($path, 'uploads/');
        if (!$allowed && !$this->settings->bool('admin.base.site_status', true)) {
            return response($this->settings->string('admin.base.site_notice', '网站维护中，请稍后访问。'), 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => '600',
            ]);
        }
        return $next($request);
    }
}
