<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Category;
use app\model\Media;
use app\service\ResilientCache;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\facade\Db;
use think\response\View;

final class Index extends BaseController
{
    public function __construct(\think\App $app, private readonly ResilientCache $cache, private readonly FrontendData $frontend, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): View
    {
        $slideLimit = $this->settings->int('admin.base.ui_slide_max', 8, 0, 100) ?: 100;
        $payload = $this->cache->remember('home:mxone:v2:' . $slideLimit, 120, static function () use ($slideLimit): array {
            return [
                'latest' => Media::where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->order('id', 'desc')->limit(18)->select()->toArray(),
                'popular' => Media::where('status', 'published')->whereNull('deleted_at')->order('view_count', 'desc')->order('id', 'desc')->limit(10)->select()->toArray(),
                'categories' => Category::where('status', 'published')->whereNull('deleted_at')->whereNull('parent_id')
                    ->order('sort_order', 'asc')->select()->toArray(),
                'slides' => Db::table('ffx_slides')->where('status', 'enabled')
                    ->where(function ($query): void { $query->whereNull('starts_at')->whereOr('starts_at', '<=', gmdate('Y-m-d H:i:s')); })
                    ->where(function ($query): void { $query->whereNull('ends_at')->whereOr('ends_at', '>=', gmdate('Y-m-d H:i:s')); })
                    ->order('sort_order')->limit($slideLimit)->select()->toArray(),
                'specials' => Db::table('ffx_topics')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit(4)->select()->toArray(),
                'news' => Db::table('ffx_articles')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit(6)->select()->toArray(),
            ];
        });
        $latest = $this->frontend->vodList($payload['latest']);
        $featured = $this->frontend->vodList($payload['popular']);
        $channels = [];
        foreach ($payload['categories'] as $category) {
            $items = Media::where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $category['id'])
                ->order('published_at', 'desc')->order('id', 'desc')->limit(12)->select()->toArray();
            $channels[] = ['type' => $this->frontend->type($category), 'items' => $this->frontend->vodList($items)];
        }
        return view(ff_theme_view('index/index'), $this->frontend->shared($this->settings->string('admin.base.site_name', (string) config('feifei.site_name'))) + [
            'featured' => $featured,
            'latest' => $latest,
            'channels' => $channels,
            'homeSpecials' => array_map(fn (array $item): array => $this->frontend->special($item), $payload['specials']),
            'homeNews' => array_map(fn (array $item): array => $this->frontend->news($item), $payload['news']),
            'slides' => $payload['slides'],
            'pageActive' => 'home',
        ]);
    }
}
