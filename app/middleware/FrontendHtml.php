<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\HtmlMinifier;
use app\service\SiteSettings;
use Closure;
use think\Request;
use think\Response;

/** Applies the frontend setting to the final rendered HTML, not to template sources. */
final class FrontendHtml
{
    public function __construct(private readonly SiteSettings $settings, private readonly HtmlMinifier $minifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $path = ltrim(strtolower($request->pathinfo()), '/');
        $entry = strtolower(basename((string) $request->baseFile()));
        $controller = str_replace(['/', '\\'], '.', strtolower($request->controller()));
        if ($entry === 'admin.php' || $entry === 'install.php'
            || preg_match('~^(?:admin|api|install|health)(?:[/.]|$)~', $path)
            || preg_match('~^(?:admin|api|install)(?:\.|$)~', $controller)
            || $response->getCode() < 200 || $response->getCode() >= 300
            || in_array($response->getCode(), [204, 206], true)) return $response;

        $headers = array_change_key_case($response->getHeader(), CASE_LOWER);
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
        if ($type !== 'text/html' || !empty($headers['content-encoding'])
            || isset($headers['etag']) || isset($headers['content-md5']) || isset($headers['digest'])
            || !empty($headers['content-disposition'])
            || preg_match('/(?:^|,)\s*no-transform\s*(?:,|$)/i', (string) ($headers['cache-control'] ?? ''))
            || !$this->settings->bool('admin.base.html_minify')) return $response;

        $content = $this->minifier->minify($response->getContent());
        $response->content($content);
        foreach ($response->getHeader() as $name => $value) {
            if (strtolower($name) === 'content-length') $response->header([$name => (string) strlen($content)]);
        }
        return $response;
    }
}
