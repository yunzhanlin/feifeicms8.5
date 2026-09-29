<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\PasswordHasher;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Users extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly PasswordHasher $hasher, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $status = (string) $this->request->param('status', '');
        $group = mb_substr(trim((string) $this->request->param('group', '')), 0, 50);
        $query = Db::table('ffx_users')->whereNull('deleted_at')->order('id', 'desc');
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword): void {
                $query->whereLike('username', '%' . $keyword . '%')->whereLike('email', '%' . $keyword . '%', 'OR');
            });
        }
        if (in_array($status, ['active', 'disabled', 'banned'], true)) $query->where('status', $status); else $status = '';
        if ($group !== '') $query->where('group_key', $group);
        return view('/admin/users/index', [
            'items' => $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => array_filter(['wd' => $keyword, 'status' => $status, 'group' => $group])]),
            'keyword' => $keyword, 'status' => $status, 'group' => $group,
            'groups' => Db::table('ffx_users')->whereNull('deleted_at')->distinct(true)->column('group_key'),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->requireUser($id));
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $data = $this->payload();
        $password = (string) $this->request->post('password', '');
        if ($data['username'] === '' || mb_strlen($password) < 8) {
            return response('用户名不能为空，密码至少 8 位', 422);
        }
        if ($this->duplicateExists((string) $data['username'], $data['email'], 0)) return response('用户名或邮箱已存在', 422);
        $data['password_hash'] = $this->hasher->hash($password);
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $id = Db::table('ffx_users')->insertGetId($data);
        $safe = $data;
        unset($safe['password_hash']);
        $this->audit->record('user.create', 'user', $id, null, $safe);
        return redirect('/admin/users/' . $id . '/edit');
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireUser($id);
        unset($before['password_hash']);
        $data = $this->payload();
        if ($this->duplicateExists((string) $data['username'], $data['email'], $id)) return response('用户名或邮箱已存在', 422);
        $password = (string) $this->request->post('password', '');
        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                return response('新密码至少 8 位', 422);
            }
            $data['password_hash'] = $this->hasher->hash($password);
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_users')->where('id', $id)->update($data);
        $safe = $data;
        unset($safe['password_hash']);
        $this->audit->record('user.update', 'user', $id, $before, $safe);
        return redirect('/admin/users/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireUser($id);
        unset($before['password_hash']);
        $data = ['status' => 'disabled', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_users')->where('id', $id)->update($data);
        $this->audit->record('user.archive', 'user', $id, $before, $data);
        return redirect('/admin/users');
    }

    public function batch(): Response
    {
        $this->guardCsrf();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])), static fn (int $id): bool => $id > 0)));
        $action = (string) $this->request->post('action', '');
        if ($ids === [] || !in_array($action, ['enable', 'disable', 'ban', 'add_points', 'archive'], true)) return response('请选择用户和批量操作', 422);
        $ids = array_slice($ids, 0, 500);
        $before = Db::table('ffx_users')->whereIn('id', $ids)->whereNull('deleted_at')->field('id,status,points')->select()->toArray();
        if ($action === 'add_points') {
            $points = max(-1000000, min(1000000, (int) $this->request->post('points_delta', 0)));
            Db::table('ffx_users')->whereIn('id', $ids)->whereNull('deleted_at')->inc('points', $points)->update(['updated_at' => gmdate('Y-m-d H:i:s')]);
            $after = ['points_delta' => $points];
        } else {
            $status = match ($action) { 'enable' => 'active', 'ban' => 'banned', default => 'disabled' };
            $after = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
            if ($action === 'archive') $after['deleted_at'] = gmdate('Y-m-d H:i:s');
            Db::table('ffx_users')->whereIn('id', $ids)->whereNull('deleted_at')->update($after);
        }
        $this->audit->record('user.batch.' . $action, 'user', implode(',', $ids), $before, $after);
        return redirect('/admin/users');
    }

    public function logs(int $id): Response
    {
        $user = $this->requireUser($id);
        unset($user['password_hash']);
        return view('/admin/users/logs', [
            'user' => $user,
            'history' => Db::table('ffx_watch_history')->alias('h')->leftJoin(['ffx_media' => 'm'], 'm.id=h.media_id')->where('h.user_id', $id)->field('h.*,m.title')->order('h.watched_at', 'desc')->limit(100)->select()->toArray(),
            'favorites' => Db::table('ffx_favorites')->alias('f')->leftJoin(['ffx_media' => 'm'], 'm.id=f.media_id')->where('f.user_id', $id)->field('f.*,m.title')->order('f.created_at', 'desc')->limit(100)->select()->toArray(),
            'comments' => Db::table('ffx_comments')->where('user_id', $id)->order('id', 'desc')->limit(100)->select()->toArray(),
            'orders' => Db::table('ffx_orders')->where('user_id', $id)->order('id', 'desc')->limit(100)->select()->toArray(),
        ]);
    }

    private function form(?array $item): Response
    {
        if ($item !== null) {
            unset($item['password_hash']);
        }
        return view('/admin/users/edit', [
            'item' => $item ?? ['id' => 0, 'username' => '', 'email' => '', 'points' => 0, 'group_key' => 'member', 'status' => 'active', 'expires_at' => ''],
            'csrf' => $this->csrf->get(), 'isNew' => $item === null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $status = (string) $this->request->post('status', 'active');
        return [
            'username' => mb_substr(trim((string) $this->request->post('username', '')), 0, 80),
            'email' => (($email = trim((string) $this->request->post('email', ''))) !== '') ? mb_substr($email, 0, 255) : null,
            'points' => (int) $this->request->post('points', 0),
            'group_key' => mb_substr(trim((string) $this->request->post('group_key', 'member')), 0, 50),
            'status' => in_array($status, ['active', 'disabled', 'banned'], true) ? $status : 'active',
            'expires_at' => (($expires = trim((string) $this->request->post('expires_at', ''))) !== '') ? str_replace('T', ' ', $expires) : null,
        ];
    }

    private function requireUser(int $id): array
    {
        $row = Db::table('ffx_users')->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '用户不存在');
        }
        return $row;
    }

    private function duplicateExists(string $username, mixed $email, int $exceptId): bool
    {
        $query = Db::table('ffx_users')->whereNull('deleted_at')->where(function ($query) use ($username, $email): void {
            $query->where('username', $username);
            if (is_string($email) && $email !== '') $query->whereOr('email', $email);
        });
        if ($exceptId > 0) $query->where('id', '<>', $exceptId);
        return $query->count() > 0;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
