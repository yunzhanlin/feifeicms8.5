<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\Media;
use app\service\FrontendCache;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\facade\Db;
use think\response\View;

final class Index extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendCache $cache, private readonly FrontendData $frontend, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): View
    {
        $shared = $this->frontend->shared($this->settings->string('admin.base.site_name', (string) config('feifei.site_name')));
        $categories = is_array($shared['navCategories'] ?? null) ? $shared['navCategories'] : [];
        $payload = $this->cache->home(static function () use ($categories): array {
            $channels = [];
            foreach ($categories as $category) {
                $channels[] = [
                    'category' => $category,
                    'items' => Media::where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $category['id'])
                        ->order('published_at', 'desc')->order('id', 'desc')->limit(12)->select()->toArray(),
                ];
            }
            return [
                'latest' => Media::where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->order('id', 'desc')->limit(18)->select()->toArray(),
                'popular' => Media::where('status', 'published')->whereNull('deleted_at')->order('view_count', 'desc')->order('id', 'desc')->limit(10)->select()->toArray(),
                'channels' => $channels,
                'specials' => Db::table('ffx_topics')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit(4)->select()->toArray(),
                'news' => Db::table('ffx_articles')->where('status', 'published')->whereNull('deleted_at')->order('published_at', 'desc')->limit(6)->select()->toArray(),
            ];
        });
        $latest = $this->frontend->vodList($payload['latest']);
        $featured = $this->frontend->vodList($payload['popular']);
        $channels = [];
        foreach ($payload['channels'] as $channel) {
            $channels[] = ['type' => $this->frontend->type($channel['category']), 'items' => $this->frontend->vodList($channel['items'])];
        }
        return view(ff_theme_view('index/index'), $shared + [
            'featured' => $featured,
            'latest' => $latest,
            'channels' => $channels,
            'homeSpecials' => array_map(fn (array $item): array => $this->frontend->special($item), $payload['specials']),
            'homeNews' => array_map(fn (array $item): array => $this->frontend->news($item), $payload['news']),
            'slides' => $shared['siteSlides'] ?? [],
            'pageActive' => 'home',
        ]);
    }
}
