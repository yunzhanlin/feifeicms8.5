<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\response\View;

final class Special extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly SiteSettings $settings) { parent::__construct($app); }

    public function detail(int $id): View
    {
        $item = Db::table('ffx_topics')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($item === null) { throw new HttpException(404, '专题不存在或未发布'); }
        $vod = Db::table('ffx_topic_media')->alias('tm')->join(['ffx_media' => 'm'], 'm.id = tm.media_id')
            ->where('tm.topic_id', $id)->where('m.status', 'published')->whereNull('m.deleted_at')->order('tm.sort_order')->field('m.*')->select()->toArray();
        return view(ff_theme_view('special/detail'), $this->frontend->shared((string) $item['title'] . ' - ' . $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'))) + ['item' => $this->frontend->special($item), 'vods' => $this->frontend->vodList($vod), 'pageActive' => 'special']);
    }
}
