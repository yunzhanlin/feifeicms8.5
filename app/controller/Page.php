<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\CsrfToken;
use app\service\SiteSettings;
use think\facade\Db;
use think\facade\Session;
use think\response\View;
use think\Response;

/**
 * FeiFeiCMS public aggregate pages.
 *
 * Keeping these routes in one controller mirrors the old Map/Vod aggregate
 * actions while leaving the MXOne template files independent from storage.
 */
final class Page extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly CsrfToken $csrf, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function latest(): View
    {
        return $this->mediaPage('published_at', 'vod/latest', '最近更新');
    }

    public function hot(): View
    {
        return $this->mediaPage('view_count', 'vod/hot', '热播推荐');
    }

    public function news(): View
    {
        $pager = Db::table('ffx_articles')->where('status', 'published')->whereNull('deleted_at')
            ->order('published_at', 'desc')->order('id', 'desc')
            ->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('news/index'), $this->frontend->shared('资讯 - ' . $this->siteName()) + [
            'news' => array_map(fn (array $row): array => $this->frontend->news($row), $pager->items()),
            'pager' => $pager->render(), 'pageActive' => 'news',
        ]);
    }

    public function special(): View
    {
        $pager = Db::table('ffx_topics')->where('status', 'published')->whereNull('deleted_at')
            ->order('published_at', 'desc')->order('id', 'desc')
            ->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('special/index'), $this->frontend->shared('专题 - ' . $this->siteName()) + [
            'specials' => array_map(fn (array $row): array => $this->frontend->special($row), $pager->items()),
            'pager' => $pager->render(), 'pageActive' => 'special',
        ]);
    }

    public function person(): View
    {
        $sid = max(0, (int) $this->request->get('sid', 0));
        $query = Db::table('ffx_people')->where('status', 'published')->whereNull('deleted_at');
        if ($sid === 8) $query->where('kind', 'person');
        if ($sid === 9) $query->where('kind', 'role');
        $pager = $query->order('updated_at', 'desc')->order('id', 'desc')
            ->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('person/index'), $this->frontend->shared('明星角色 - ' . $this->siteName()) + [
            'people' => array_map(fn (array $row): array => $this->frontend->person($row), $pager->items()),
            'pager' => $pager->render(), 'sid' => $sid, 'pageActive' => 'person',
        ]);
    }

    public function map(): View
    {
        $limit = $this->settings->int('admin.content.sitemap_limit', 1000, 1, 10000);
        $vod = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit($limit)->select()->toArray();
        $news = Db::table('ffx_articles')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit($limit)->select()->toArray();
        $specials = Db::table('ffx_topics')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit($limit)->select()->toArray();
        return view(ff_theme_view('page/map'), $this->frontend->shared('网站地图 - ' . $this->siteName()) + [
            'vods' => $this->frontend->vodList($vod),
            'news' => array_map(fn (array $row): array => $this->frontend->news($row), $news),
            'specials' => array_map(fn (array $row): array => $this->frontend->special($row), $specials),
        ]);
    }

    public function rss(): Response
    {
        $vod = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit($this->settings->int('admin.content.rss_limit', 50, 1, 1000))->select()->toArray();
        $content = view(ff_theme_view('page/rss'), $this->frontend->shared($this->siteName()) + ['vods' => $this->frontend->vodList($vod)])->getContent();
        return response($content, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    public function payment(): View
    {
        return view(ff_theme_view('page/payment'), $this->frontend->shared('充值中心 - ' . $this->siteName()) + [
            'config' => $this->paymentConfig(), 'csrf' => $this->csrf->get(),
            'isLoggedIn' => (int) Session::get('user_id', 0) > 0,
            'message' => mb_substr(trim((string) $this->request->get('message', '')), 0, 200),
        ]);
    }

    public function vip(): View
    {
        return view(ff_theme_view('page/vip'), $this->frontend->shared('VIP - ' . $this->siteName()) + ['config' => $this->paymentConfig()]);
    }

    private function mediaPage(string $order, string $template, string $title): View
    {
        $pager = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')
            ->order($order, 'desc')->order('id', 'desc')
            ->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view($template), $this->frontend->shared($title . ' - ' . $this->siteName()) + [
            'vods' => $this->frontend->vodList($pager->items()), 'total' => $pager->total(), 'pager' => $pager->render(),
        ]);
    }

    /** @return array<string, string> */
    private function paymentConfig(): array
    {
        return [
            'pay_name' => $this->settings->string('admin.pay.pay_name', '站内充值'),
            'pay_notice' => $this->settings->string('admin.pay.pay_notice', '请输入管理员发放的充值卡密。'),
            'pay_enabled' => $this->settings->bool('admin.pay.pay_enabled') ? '1' : '0',
            'vip_enabled' => $this->settings->bool('admin.pay.vip_enabled', true) ? '1' : '0',
        ];
    }

    private function siteName(): string
    {
        return $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'));
    }

    private function pageSize(): int
    {
        return $this->settings->int('admin.content.page_size', (int) config('feifei.page_size', 24), 1, 100);
    }
}
