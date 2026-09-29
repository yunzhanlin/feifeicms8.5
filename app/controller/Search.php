<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\search\SearchManager;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\response\View;

final class Search extends BaseController
{
    public function __construct(\think\App $app, private readonly SearchManager $search, private readonly FrontendData $frontend, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): View
    {
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $page = max(1, (int) $this->request->param('pg', $this->request->param('p', 1)));
        $pageSize = $this->settings->int('admin.content.page_size', (int) config('feifei.page_size', 24), 1, 60);
        $searchResult = $keyword === '' ? null : $this->search->search($keyword, $page, $pageSize);
        $result = $searchResult === null ? null : [
            'items' => $searchResult->items,
            'total' => $searchResult->total,
            'page' => $searchResult->page,
            'pageSize' => $searchResult->pageSize,
            'driver' => $searchResult->driver,
        ];

        $items = $result === null ? [] : $this->frontend->vodList((array) $result['items']);
        $total = (int) ($result['total'] ?? 0);
        $pages = max(1, (int) ceil($total / $pageSize));
        return view(ff_theme_view('search/index'), $this->frontend->shared(($keyword !== '' ? $keyword . ' - ' : '') . '搜索', $keyword) + [
            'keyword' => $keyword,
            'result' => $result,
            'vods' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'message' => $keyword === '' ? '请输入搜索关键词' : ($total === 0 ? '没有找到相关内容' : ''),
            'pageActive' => 'search',
        ]);
    }
}
