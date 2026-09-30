<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\CsrfToken;
use app\service\SiteSettings;
use app\service\LegacyTemplate;
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
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly CsrfToken $csrf, private readonly SiteSettings $settings, private readonly LegacyTemplate $legacyTemplate)
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

    public function features(): View
    {
        $groups = [
            ['name' => '影视内容', 'items' => [
                ['name' => '首页聚合', 'url' => '/', 'note' => '轮播、热播、最新、分类频道、专题和资讯'],
                ['name' => '分类筛选', 'url' => '/list/1', 'note' => '类型、地区、语言、年份和排序'],
                ['name' => '搜索', 'url' => '/search', 'note' => '片名、别名、演员、导演、标签和简介'],
                ['name' => '最近更新', 'url' => '/latest', 'note' => '按发布时间分页'],
                ['name' => '热播榜', 'url' => '/hot', 'note' => '按播放量分页'],
                ['name' => '详情与播放', 'url' => '/features#live-demo', 'note' => '线路、选集、权限、评分、顶踩、收藏、评论、剧情和资源附件'],
            ]],
            ['name' => '扩展内容', 'items' => [
                ['name' => '资讯', 'url' => '/news', 'note' => '资讯列表与正文'],
                ['name' => '专题', 'url' => '/special', 'note' => '专题正文与关联影片'],
                ['name' => '明星角色', 'url' => '/person', 'note' => '人物档案与关联作品'],
                ['name' => '分集剧情', 'url' => '/features#scenario-demo', 'note' => '独立剧情列表和单集详情'],
                ['name' => '网站地图', 'url' => '/map', 'note' => '栏目、影片、资讯、专题入口'],
                ['name' => 'RSS', 'url' => '/rss', 'note' => '标准 RSS 2.0 输出'],
            ]],
            ['name' => '用户互动', 'items' => [
                ['name' => '登录注册', 'url' => '/user/login', 'note' => '会话、限流、注册审核'],
                ['name' => '会员中心', 'url' => '/user/center', 'note' => '积分、VIP、播放记录和收藏'],
                ['name' => '留言板', 'url' => '/guestbook', 'note' => '留言、审核与管理员回复'],
                ['name' => 'VIP 与积分', 'url' => '/vip', 'note' => 'VIP 权限、积分解锁与卡密充值'],
                ['name' => '充值中心', 'url' => '/payment', 'note' => '登录用户兑换卡密'],
                ['name' => '视频接口', 'url' => '/api.php/provide/vod?ac=list', 'note' => 'MacCMS 兼容 JSON 输出'],
            ]],
            ['name' => '运营组件', 'items' => [
                ['name' => '自定义导航', 'url' => '/features#operations-demo', 'note' => '系统入口与后台导航同时展示'],
                ['name' => '轮播图', 'url' => '/features#operations-demo', 'note' => '有效期、桌面图、移动图和跳转地址'],
                ['name' => '广告位', 'url' => '/features#operations-demo', 'note' => 'header、home_top、home_middle、vod_detail、vod_play、footer'],
                ['name' => '友情链接', 'url' => '/features#operations-demo', 'note' => '文字或图片链接'],
                ['name' => '模板标签', 'url' => '/features#template-calls', 'note' => 'FeiFeiCMS 兼容查询和 URL 函数'],
            ]],
        ];
        $examples = [
            ['title' => '分类循环', 'code' => <<<'TPL'
{volist name=":ff_mysql_list('limit:20')" id="type"}
<a href="{:ff_url('type',$type.list_id)}">{$type.list_name}</a>
{/volist}
TPL],
            ['title' => '视频循环', 'code' => <<<'TPL'
{volist name=":ff_mysql_vod('cid:1;limit:12;order:vod_hits;sort:desc')" id="vod"}
<a href="{:ff_url('vod',$vod.vod_id)}">{$vod.vod_name}</a>
{/volist}
TPL],
            ['title' => '播放地址', 'code' => <<<'TPL'
{:ff_play_url($vod.vod_id, 0, 0)}
{:ff_url_play($vod.list_id, $vod.list_dir, $vod.vod_id, $vod.vod_ename, 0, 0)}
TPL],
            ['title' => '资讯、专题、人物与标签', 'code' => "ff_mysql_news('limit:10;order:news_addtime')\nff_mysql_special('limit:6')\nff_mysql_star('limit:12')\nff_mysql_role('limit:12')\nff_mysql_tags('scope:media;limit:20')"],
            ['title' => '剧情、评论与运营组件', 'code' => "ff_mysql_scenario('vodid:16;limit:100')\nff_mysql_forum('cid:16;limit:20')\nff_mysql_slide('limit:8')\nff_mysql_nav('limit:30')\nff_mysql_link('limit:30')\nff_mysql_ads('slot:home_top;limit:1')"],
            ['title' => '采集兼容 API', 'code' => "GET /api.php/provide/vod?ac=list\nGET /api.php/provide/vod?ac=detail&ids=16\nGET /api/provide/vod?ac=detail&t=1&pg=1"],
        ];
        return view(ff_theme_view('page/features'), $this->frontend->shared('模板功能与调用 - ' . $this->siteName()) + [
            'pageActive' => 'features', 'featureGroups' => $groups, 'templateExamples' => $examples,
            'demoVod' => $this->legacyTemplate->vod('limit:6;order:vod_hits;sort:desc'),
            'demoNews' => $this->legacyTemplate->news('limit:3;order:news_addtime;sort:desc'),
            'demoSpecials' => $this->legacyTemplate->specials('limit:3'),
            'demoPeople' => $this->legacyTemplate->people('limit:6'),
            'demoTags' => $this->legacyTemplate->tags('scope:media;limit:20'),
            'demoScenarios' => $this->legacyTemplate->scenarios('limit:6'),
            'demoComments' => $this->legacyTemplate->comments('target:site;limit:3'),
            'demoSlides' => $this->legacyTemplate->slides('limit:8'),
            'demoLinks' => $this->legacyTemplate->links('limit:30'),
            'demoAds' => $this->legacyTemplate->ads('limit:20'),
        ]);
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
