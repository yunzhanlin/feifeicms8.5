<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CsrfToken;
use app\service\FrontendData;
use app\service\ResilientCache;
use app\service\SiteSettings;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Guestbook extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly CsrfToken $csrf, private readonly ResilientCache $cache, private readonly SiteSettings $settings) { parent::__construct($app); }

    public function index(): Response
    {
        $message = '';
        if ($this->request->isPost()) {
            if (!$this->settings->bool('admin.comments.user_forum', true)) return response('留言功能已关闭', 403);
            if (!$this->csrf->verify($this->request->post('_token'))) {
                $message = '请求已过期，请重新提交。';
            } else {
                $content = mb_substr(trim((string) $this->request->post('content', '')), 0, $this->settings->int('admin.comments.max_length', 1000, 1, 10000));
                $rateKey = 'rate:guestbook:' . hash('sha256', (string) $this->request->ip());
                if ($content === '') {
                    $message = '留言内容不能为空。';
                } elseif ((int) $this->cache->get($rateKey, 0) > 0) {
                    $message = '提交过于频繁，请稍后再试。';
                } else {
                    $userId = (int) Session::get('user_id', 0);
                    Db::table('ffx_comments')->insert([
                        'user_id' => $userId > 0 ? $userId : null, 'target_type' => 'site', 'target_id' => null,
                        'title' => '网友留言', 'content' => $content,
                        'author_name' => $userId > 0 ? (string) Session::get('user_name', '会员') : '访客',
                        'ip_address' => (string) $this->request->ip(), 'status' => $this->settings->bool('admin.comments.user_check', true) ? 'pending' : 'approved',
                        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $this->cache->set($rateKey, 1, $this->settings->int('admin.comments.user_second', 30, 1, 86400));
                    $message = $this->settings->bool('admin.comments.user_check', true) ? '留言已提交，审核后显示。' : '留言发布成功。';
                }
            }
        }
        $pager = Db::table('ffx_comments')->where('target_type', 'site')->where('status', 'approved')->whereNull('parent_id')->order('created_at', 'desc')
            ->paginate(['list_rows' => $this->settings->int('admin.comments.page_size', 30, 1, 100), 'query' => $this->request->get()]);
        $items = [];
        foreach ($pager->items() as $row) {
            $replies = Db::table('ffx_comments')->where('parent_id', (int) $row['id'])->where('status', 'approved')->order('created_at')->select()->toArray();
            $items[] = [
                'forum_title' => (string) ($row['title'] ?: '网友留言'), 'forum_content' => $row['content'],
                'forum_ip' => $this->maskIp((string) ($row['ip_address'] ?? '')), 'forum_addtime' => strtotime((string) $row['created_at']),
                'guestbook_replies' => array_map(static fn (array $reply): array => ['forum_content' => $reply['content'], 'forum_addtime' => strtotime((string) $reply['created_at'])], $replies),
            ];
        }
        return view(ff_theme_view('guestbook/index'), $this->frontend->shared('留言板') + ['items' => $items, 'pager' => $pager->render(), 'message' => $message, 'csrf' => $this->csrf->get(), 'pageActive' => 'guestbook']);
    }

    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return (string) preg_replace('/\.\d+$/', '.*', $ip);
        return $ip === '' ? '-' : mb_substr($ip, 0, 8) . '*';
    }
}
