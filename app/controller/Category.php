<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\response\View;

final class Category extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function show(int $id): View
    {
        $category = Db::table('ffx_categories')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($category === null) {
            throw new HttpException(404, '分类不存在或未启用');
        }

        return match ((string) $category['content_type']) {
            'article' => $this->news($category),
            'topic' => $this->special($category),
            'comment' => $this->forum($category),
            'person' => $this->people($category),
            default => $this->vod($category),
        };
    }

    /** @param array<string, mixed> $category */
    private function vod(array $category): View
    {
        $all = Db::table('ffx_categories')->where('status', 'published')->whereNull('deleted_at')->select()->toArray();
        $query = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')
            ->whereIn('category_id', $this->frontend->categoryIds($all, (int) $category['id']));
        foreach (['area' => 'area', 'year' => 'release_year', 'language' => 'language'] as $param => $column) {
            $value = trim((string) $this->request->param($param, ''));
            if ($value !== '') {
                $param === 'year' ? $query->where($column, (int) $value) : $query->whereLike($column, '%' . $value . '%');
            }
        }
        $typeFilter = trim((string) $this->request->param('type', ''));
        if ($typeFilter !== '') $query->whereLike('metadata', '%' . $typeFilter . '%');
        $order = (string) $this->request->param('order', 'addtime');
        $orderColumn = ['hits' => 'view_count', 'gold' => 'rating', 'id' => 'id'][$order] ?? 'published_at';
        $pager = $query->order($orderColumn, 'desc')->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        $selected = [
            'order' => $order,
            'type' => trim((string) $this->request->param('type', '')),
            'area' => trim((string) $this->request->param('area', '')),
            'year' => trim((string) $this->request->param('year', '')),
            'language' => trim((string) $this->request->param('language', '')),
        ];
        return view(ff_theme_view('vod/type'), $this->frontend->shared((string) $category['name'] . ' - ' . $this->siteName()) + [
            'category' => $category,
            'type' => $this->frontend->type($category),
            'filterOptions' => $this->frontend->filters($category),
            'filters' => $selected,
            'vods' => $this->frontend->vodList($pager->items()),
            'vodTotal' => $pager->total(),
            'pager' => $pager->render(),
            'selected' => $selected,
            'pageActive' => 'type',
        ]);
    }

    /** @param array<string, mixed> $category */
    private function news(array $category): View
    {
        $pager = Db::table('ffx_articles')->where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $category['id'])
            ->order('published_at', 'desc')->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('news/index'), $this->frontend->shared((string) $category['name'] . ' - ' . $this->siteName()) + [
            'category' => $category,
            'news' => array_map(fn (array $item): array => $this->frontend->news($item), $pager->items()),
            'pager' => $pager->render(), 'pageActive' => 'news',
        ]);
    }

    /** @param array<string, mixed> $category */
    private function special(array $category): View
    {
        $pager = Db::table('ffx_topics')->where('status', 'published')->whereNull('deleted_at')->where('category_id', (int) $category['id'])->order('published_at', 'desc')
            ->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('special/index'), $this->frontend->shared((string) $category['name'] . ' - ' . $this->siteName()) + [
            'category' => $category,
            'specials' => array_map(fn (array $item): array => $this->frontend->special($item), $pager->items()),
            'pager' => $pager->render(), 'pageActive' => 'special',
        ]);
    }

    /** @param array<string, mixed> $category */
    private function people(array $category): View
    {
        $pager = Db::table('ffx_people')->where('status', 'published')->whereNull('deleted_at')
            ->order('updated_at', 'desc')->paginate(['list_rows' => $this->pageSize(), 'query' => $this->request->get()]);
        return view(ff_theme_view('person/index'), $this->frontend->shared((string) $category['name'] . ' - ' . $this->siteName()) + [
            'category' => $category,
            'people' => array_map(fn (array $item): array => $this->frontend->person($item), $pager->items()),
            'pager' => $pager->render(), 'sid' => 0, 'pageActive' => 'person',
        ]);
    }

    /** @param array<string, mixed> $category */
    private function forum(array $category): View
    {
        $pager = Db::table('ffx_comments')->where('status', 'approved')->where('target_type', 'site')
            ->order('created_at', 'desc')->paginate(['list_rows' => $this->settings->int('admin.comments.page_size', 30, 1, 100), 'query' => $this->request->get()]);
        return view('forum/index', $this->frontend->shared((string) $category['name'] . ' - ' . $this->siteName()) + [
            'category' => $category, 'items' => $pager->items(), 'pagination' => $pager->render(),
        ]);
    }

    private function pageSize(): int
    {
        return $this->settings->int('admin.content.page_size', (int) config('feifei.page_size', 24), 1, 100);
    }

    private function siteName(): string
    {
        return $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'));
    }
}
