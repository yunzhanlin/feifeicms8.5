<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Danmaku extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SiteSettings $settings) { parent::__construct($app); }
    public function index(): Response
    {
        $wd = mb_substr(trim((string) $this->request->get('wd', '')), 0, 100);
        $query = Db::table('ffx_danmaku')->alias('d')->leftJoin(['ffx_users' => 'u'], 'u.id=d.user_id')->field('d.*,u.username')->order('d.id', 'desc');
        if ($wd !== '') $query->whereLike('d.text', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $wd) . '%');
        $pager = $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => ['wd' => $wd]]);
        return view('/admin/danmaku/index', ['items' => $pager->items(), 'pagination' => $pager->render(), 'wd' => $wd, 'csrf' => $this->csrf->get()]);
    }
    public function delete(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
        $row = Db::table('ffx_danmaku')->where('id', $id)->find(); if ($row === null) throw new HttpException(404, '弹幕不存在');
        Db::table('ffx_danmaku')->where('id', $id)->delete(); $this->audit->record('danmaku.delete', 'danmaku', $id, $row, null);
        return redirect('/admin/danmaku');
    }
}
