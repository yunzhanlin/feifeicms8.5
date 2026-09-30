<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\LegacyRewriteRules;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Log;
use think\Response;

/** Resolves custom FeiFeiCMS URLs back into modern controllers. */
final class Rewrite extends BaseController
{
    public function __construct(\think\App $app, private readonly SiteSettings $settings, private readonly LegacyRewriteRules $rules)
    {
        parent::__construct($app);
    }

    public function dispatch(string $rewritePath = ''): Response
    {
        if (!$this->settings->bool('admin.rewrite.url_rewrite', true) || !$this->settings->bool('admin.rewrite.url_router_on', true)) $this->notFound();
        $path = trim(rawurldecode($rewritePath !== '' ? $rewritePath : $this->request->pathinfo()), '/');
        if ($path === '' || strlen($path) > 1000 || str_contains($path, '..') || str_contains($path, "\0")) $this->notFound();
        $suffix = $this->settings->string('admin.rewrite.url_html_suffix', '.html');
        if (!in_array($suffix, ['', '.html', '.htm', '.shtml', '.shtm'], true)) $suffix = '.html';
        $requestPath = (string) (parse_url((string) $this->request->server('REQUEST_URI', ''), PHP_URL_PATH) ?: '');
        if ($suffix !== '') {
            if (str_ends_with(strtolower($path), strtolower($suffix))) {
                $path = substr($path, 0, -strlen($suffix));
            } else {
                if (!str_ends_with(strtolower($requestPath), strtolower($suffix))) $this->notFound();
            }
        } elseif (preg_match('/\.(?:html?|shtml?|shtm)$/i', $requestPath)) {
            $this->notFound();
        }
        try {
            $route = $this->rules->resolveRoute($path, $this->settings->string('admin.rewrite.rewrite_route', ''));
        } catch (\InvalidArgumentException) {
            $route = null;
        }
        if ($route === null) Log::notice('URL 自定义规则未匹配', ['path' => $path]);
        if ($route === null) $this->notFound();
        $module = $route['module'];
        $operation = $route['operation'];
        $params = $route['params'];
        if (isset($params['p'])) $this->request->withGet(['page' => max(1, (int) $params['p'])] + $this->request->get());

        if ($module === 'list' && in_array($operation, ['read', 'show', 'ename'], true)) {
            $id = isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : $this->idBySlug('ffx_categories', (string) ($params['id'] ?? ''));
            return $this->app->make(Category::class)->show($id);
        }
        if ($module === 'vod' && in_array($operation, ['read', 'ename'], true)) {
            $id = isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : $this->idBySlug('ffx_media', (string) ($params['id'] ?? ''));
            return $this->app->make(Vod::class)->detail($id);
        }
        if ($module === 'vod' && in_array($operation, ['play', 'eplay'], true)) {
            $id = isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : $this->idBySlug('ffx_media', (string) ($params['id'] ?? ''));
            try {
                return $this->app->make(Vod::class)->play($id, max(1, (int) ($params['sid'] ?? 1)), max(1, (int) ($params['pid'] ?? 1)));
            } catch (\Throwable $exception) {
                Log::error('URL 自定义播放路由执行失败', ['path' => $path, 'params' => $params, 'exception' => $exception->getMessage()]);
                throw $exception;
            }
        }
        if ($module === 'news' && in_array($operation, ['read', 'ename'], true)) {
            $id = isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : $this->idBySlug('ffx_articles', (string) ($params['id'] ?? ''));
            return $this->app->make(News::class)->detail($id);
        }
        if ($module === 'special' && $operation === 'read') return $this->app->make(Special::class)->detail((int) ($params['id'] ?? 0));
        if (in_array($module, ['star', 'role'], true) && $operation === 'read') {
            $id = isset($params['id']) && ctype_digit($params['id']) ? (int) $params['id'] : $this->idBySlug('ffx_people', (string) ($params['id'] ?? ''));
            return $this->app->make(Person::class)->detail($id);
        }
        if ($module === 'scenario' && $operation === 'read') {
            $scenarioId = max(0, (int) ($params['id'] ?? 0));
            return $this->app->make(Vod::class)->scenario($scenarioId);
        }
        if ($module === 'vod' && $operation === 'juqing') return $this->app->make(Vod::class)->scenarios(max(0, (int) ($params['id'] ?? 0)));
        if ($module === 'vod' && in_array($operation, ['taici', 'zixun', 'yanyuan', 'pingfen', 'kandian', 'shoubo', 'jieju', 'rss', 'yugao', 'xiazai'], true)) {
            return $this->app->make(Vod::class)->detail(max(0, (int) ($params['id'] ?? 0)));
        }
        if ($module === 'vod' && $operation === 'forum') return $this->app->make(Interaction::class)->comments(max(0, (int) ($params['id'] ?? 0)));
        if (in_array($module, ['guestbook', 'forum'], true) && $operation === 'read') return $this->app->make(Guestbook::class)->index();
        if ($module === 'user' && $operation === 'index') return $this->app->make(User::class)->center();
        Log::notice('URL 自定义规则未映射', ['path' => $path, 'module' => $module, 'operation' => $operation, 'params' => $params]);
        $this->notFound('当前自定义规则没有对应页面');
    }

    public function segments(string $u1 = '', string $u2 = '', string $u3 = '', string $u4 = '', string $u5 = '', string $u6 = '', string $u7 = '', string $u8 = ''): Response
    {
        return $this->dispatch(implode('/', array_filter([$u1, $u2, $u3, $u4, $u5, $u6, $u7, $u8], static fn (string $value): bool => $value !== '')));
    }

    private function idBySlug(string $table, string $slug): int
    {
        if ($slug === '') $this->notFound();
        $id = (int) (Db::table($table)->where('slug', $slug)->where('status', 'published')->whereNull('deleted_at')->value('id') ?: 0);
        if ($id < 1) $this->notFound();
        return $id;
    }

    private function notFound(string $message = '页面不存在'): never
    {
        throw new HttpException(404, $message);
    }
}
