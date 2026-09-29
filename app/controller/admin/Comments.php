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

final class Comments extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $status = (string) $this->request->param('status', '');
        $targetType = (string) $this->request->param('target_type', '');
        $query = Db::table('ffx_comments')->alias('c')->leftJoin(['ffx_users' => 'u'], 'u.id = c.user_id')
            ->whereNull('c.deleted_at')->field('c.*,u.username')->order('c.id', 'desc');
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('c.status', $status);
        } else {
            $status = '';
        }
        if (in_array($targetType, ['media', 'article', 'topic', 'person'], true)) {
            $query->where('c.target_type', $targetType);
        } else {
            $targetType = '';
        }
        $pager = $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => array_filter(['status' => $status, 'target_type' => $targetType])]);
        $items = $pager->items();
        foreach ($items as &$item) {
            $item['target_url'] = $this->targetUrl((string) $item['target_type'], (int) ($item['target_id'] ?? 0));
        }
        unset($item);
        return view('/admin/comments/index', ['items' => $items, 'pagination' => $pager->render(), 'status' => $status, 'targetType' => $targetType, 'csrf' => $this->csrf->get()]);
    }

    public function moderate(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireComment($id);
        $status = (string) $this->request->post('status', 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return response('审核状态无效', 422);
        }
        $data = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($this->request->has('is_pinned', 'post')) $data['is_pinned'] = (int) ((bool) $this->request->post('is_pinned'));
        Db::table('ffx_comments')->where('id', $id)->update($data);
        $this->audit->record('comment.moderate', 'comment', $id, $before, $data);
        return redirect('/admin/comments');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireComment($id);
        $data = ['status' => 'rejected', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_comments')->where('id', $id)->update($data);
        $this->audit->record('comment.archive', 'comment', $id, $before, $data);
        return redirect('/admin/comments');
    }

    public function reply(int $id): Response
    {
        $this->guardCsrf();
        $parent = $this->requireComment($id);
        $content = mb_substr(trim((string) $this->request->post('content', '')), 0, 1000);
        if ($content === '') return response('回复内容不能为空', 422);
        $now = gmdate('Y-m-d H:i:s');
        $replyId = Db::table('ffx_comments')->insertGetId([
            'user_id' => null, 'parent_id' => $id, 'target_type' => (string) $parent['target_type'],
            'target_id' => $parent['target_id'], 'episode_id' => $parent['episode_id'],
            'title' => '管理员回复', 'content' => $content, 'author_name' => '管理员',
            'ip_address' => (string) $this->request->ip(), 'status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit->record('comment.reply', 'comment', $replyId, null, ['parent_id' => $id, 'content' => $content]);
        return redirect('/admin/comments');
    }

    public function batch(): Response
    {
        $this->guardCsrf();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])), static fn (int $id): bool => $id > 0)));
        $action = (string) $this->request->post('action', '');
        if ($ids === [] || !in_array($action, ['approve', 'reject', 'delete', 'pin', 'unpin'], true)) return response('请选择评论和有效操作', 422);
        $ids = array_slice($ids, 0, 500);
        $before = Db::table('ffx_comments')->whereIn('id', $ids)->whereNull('deleted_at')->field('id,status,is_pinned')->select()->toArray();
        $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
        if ($action === 'approve') $data['status'] = 'approved';
        elseif ($action === 'reject') $data['status'] = 'rejected';
        elseif ($action === 'pin') $data['is_pinned'] = 1;
        elseif ($action === 'unpin') $data['is_pinned'] = 0;
        else $data += ['status' => 'rejected', 'deleted_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_comments')->whereIn('id', $ids)->whereNull('deleted_at')->update($data);
        $this->audit->record('comment.batch.' . $action, 'comment', implode(',', $ids), $before, $data);
        return redirect('/admin/comments');
    }

    private function targetUrl(string $type, int $id): string
    {
        return match ($type) {
            'media', 'vod' => '/vod/' . $id,
            'article', 'news' => '/news/' . $id,
            'topic', 'special' => '/special/' . $id,
            'person', 'star', 'role' => '/star/' . $id,
            default => '',
        };
    }

    private function requireComment(int $id): array
    {
        $row = Db::table('ffx_comments')->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '评论不存在');
        }
        return $row;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
