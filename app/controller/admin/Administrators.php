<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\PasswordHasher;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Administrators extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly PasswordHasher $hasher)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        return view('/admin/administrators/index', ['items' => Db::table('ffx_admins')->order('id')->select()->toArray(), 'csrf' => $this->csrf->get(), 'currentId' => (int) Session::get('admin_id', 0)]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->requireAdmin($id));
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $username = mb_substr(trim((string) $this->request->post('username', '')), 0, 80);
        $password = (string) $this->request->post('password', '');
        if ($username === '' || mb_strlen($password) < 12) {
            return response('管理员名不能为空，密码至少 12 位', 422);
        }
        if (Db::table('ffx_admins')->where('username', $username)->count() > 0) {
            return response('管理员名已存在', 422);
        }
        $data = ['username' => $username, 'password_hash' => $this->hasher->hash($password), 'email' => $this->email(), 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        $roleId = $this->roleId();
        $id = Db::transaction(function () use ($data, $roleId): int {
            $id = (int) Db::table('ffx_admins')->insertGetId($data);
            Db::table('ffx_admin_roles')->insert(['admin_id' => $id, 'role_id' => $roleId]);
            return $id;
        });
        $safe = $data; unset($safe['password_hash']);
        $this->audit->record('admin.create', 'admin', $id, null, $safe);
        return redirect('/admin/administrators/' . $id . '/edit');
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireAdmin($id);
        $username = mb_substr(trim((string) $this->request->post('username', '')), 0, 80);
        if ($username === '' || Db::table('ffx_admins')->where('username', $username)->where('id', '<>', $id)->count() > 0) {
            return response('管理员名不正确或已存在', 422);
        }
        $status = $this->request->post('status', 'active') === 'disabled' ? 'disabled' : 'active';
        if ($id === (int) Session::get('admin_id', 0)) {
            $status = 'active';
        }
        $data = ['username' => $username, 'email' => $this->email(), 'status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
        $password = (string) $this->request->post('password', '');
        if ($password !== '') {
            if (mb_strlen($password) < 12) {
                return response('新密码至少 12 位', 422);
            }
            $data['password_hash'] = $this->hasher->hash($password);
        }
        $roleId = $this->roleId();
        Db::transaction(function () use ($id, $data, $roleId): void {
            $this->protectSuperAdmin($id, $data['status'] === 'active' && Db::table('ffx_roles')->where('id', $roleId)->value('role_key') === 'super_admin');
            Db::table('ffx_admins')->where('id', $id)->update($data);
            Db::table('ffx_admin_roles')->where('admin_id', $id)->delete();
            Db::table('ffx_admin_roles')->insert(['admin_id' => $id, 'role_id' => $roleId]);
        });
        unset($before['password_hash']); $safe = $data; unset($safe['password_hash']);
        $this->audit->record('admin.update', 'admin', $id, $before, $safe);
        return redirect('/admin/administrators/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        if ($id === (int) Session::get('admin_id', 0)) {
            return response('不能删除当前登录的管理员', 422);
        }
        $before = $this->requireAdmin($id);
        if (Db::table('ffx_admins')->where('status', 'active')->count() <= 1) {
            return response('必须保留至少一个启用的管理员', 422);
        }
        Db::transaction(function () use ($id): void {
            $this->protectSuperAdmin($id, false);
            Db::table('ffx_admins')->where('id', $id)->delete();
        });
        unset($before['password_hash']);
        $this->audit->record('admin.delete', 'admin', $id, $before, null);
        return redirect('/admin/administrators');
    }

    private function form(?array $item): Response
    {
        if ($item !== null) unset($item['password_hash']);
        $roleId = $item === null ? Db::table('ffx_roles')->where('role_key', 'editor')->value('id') : Db::table('ffx_admin_roles')->where('admin_id', $item['id'])->value('role_id');
        return view('/admin/administrators/edit', ['roles' => Db::table('ffx_roles')->select()->toArray(), 'roleId' => (int) $roleId, 'item' => $item ?? ['id' => 0, 'username' => '', 'email' => '', 'status' => 'active'], 'isNew' => $item === null, 'csrf' => $this->csrf->get(), 'currentId' => (int) Session::get('admin_id', 0)]);
    }

    private function roleId(): int
    {
        $id = (int) $this->request->post('role_id', 0);
        if ($id < 1 || Db::table('ffx_roles')->where('id', $id)->count() !== 1) throw new HttpException(422, '请选择有效的管理员角色');
        return $id;
    }

    private function protectSuperAdmin(int $id, bool $remainsSuper): void
    {
        // Lock administrators in a stable order to serialize concurrent demotions.
        Db::table('ffx_admins')->order('id')->lock(true)->select();
        $supers = Db::table('ffx_admins')->alias('a')->join(['ffx_admin_roles' => 'ar'], 'ar.admin_id=a.id')
            ->join(['ffx_roles' => 'r'], 'r.id=ar.role_id')->where('a.status', 'active')->where('r.role_key', 'super_admin')->column('a.id');
        if (!$remainsSuper && in_array($id, array_map('intval', $supers), true)
            && ($id === (int) Session::get('admin_id', 0) || count($supers) <= 1)) {
            throw new HttpException(422, '不能移除当前登录或最后一个启用的超级管理员权限');
        }
    }

    private function email(): ?string
    {
        $email = trim((string) $this->request->post('email', ''));
        return $email === '' ? null : mb_substr($email, 0, 255);
    }

    private function requireAdmin(int $id): array
    {
        $row = Db::table('ffx_admins')->where('id', $id)->find();
        if ($row === null) throw new HttpException(404, '管理员不存在');
        return $row;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
