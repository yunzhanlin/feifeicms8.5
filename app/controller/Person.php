<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\FrontendData;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\response\View;

final class Person extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly SiteSettings $settings) { parent::__construct($app); }

    public function detail(int $id): View
    {
        $item = Db::table('ffx_people')->where('id', $id)->where('status', 'published')->whereNull('deleted_at')->find();
        if ($item === null) { throw new HttpException(404, '人物不存在或未发布'); }
        $vod = Db::table('ffx_media_people')->alias('mp')->join(['ffx_media' => 'm'], 'm.id = mp.media_id')
            ->where('mp.person_id', $id)->where('m.status', 'published')->whereNull('m.deleted_at')->order('mp.sort_order')->limit(12)->field('m.*,mp.credit_type,mp.character_name')->select()->toArray();
        return view(ff_theme_view('person/detail'), $this->frontend->shared((string) $item['name'] . ' - ' . $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'))) + ['item' => $this->frontend->person($item), 'vod' => $this->frontend->vodList($vod), 'pageActive' => 'person']);
    }
}
