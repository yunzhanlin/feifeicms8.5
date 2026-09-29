<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\response\View;

final class News extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly SiteSettings $settings) { parent::__construct($app); }

    public function detail(int $id): View
    {
        $item = Db::table('ffx_articles')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($item === null) { throw new HttpException(404, '资讯不存在或未发布'); }
        return view(ff_theme_view('news/detail'), $this->frontend->shared((string) $item['title'] . ' - ' . $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'))) + ['item' => $this->frontend->news($item), 'pageActive' => 'news']);
    }
}
