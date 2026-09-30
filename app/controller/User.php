<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\service\CsrfToken;
use app\service\FrontendData;
use app\service\PasswordHasher;
use app\service\RequestRateLimiter;
use app\service\SiteSettings;
use app\service\WatchHistoryService;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class User extends BaseController
{
    public function __construct(\think\App $app, private readonly FrontendData $frontend, private readonly CsrfToken $csrf, private readonly RequestRateLimiter $limiter, private readonly SiteSettings $settings, private readonly WatchHistoryService $watchHistory, private readonly PasswordHasher $passwordHasher)
    {
        parent::__construct($app);
    }

    public function login(): Response
    {
        $redirect = $this->safeRedirect((string) $this->request->param('redirect', '/user/center'));
        $message = '';
        if ($this->request->isPost()) {
            if (!$this->csrf->verify($this->request->post('_token'))) {
                $message = '请求已过期，请重新提交。';
            } else {
                $username = mb_substr(trim((string) $this->request->post('user_name', '')), 0, 80);
                $password = (string) $this->request->post('user_pwd', '');
                $rateKey = 'rate:user-login:' . hash('sha256', (string) $this->request->ip());
                $attempts = $this->limiter->attempts($rateKey);
                $user = $attempts >= 10 ? null : Db::table('ffx_users')->where('username', $username)->where('status', 'active')->whereNull('deleted_at')->find();
                if ($user !== null && $this->passwordHasher->verify((string) $user['password_hash'], $password)) {
                    Session::regenerate(true);
                    Session::set('user_id', (int) $user['id']);
                    Session::set('user_name', (string) $user['username']);
                    $loginUpdate = [
                        'last_login_ip' => (string) $this->request->ip(), 'last_login_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ];
                    if ($this->passwordHasher->needsRehash((string) $user['password_hash'])) $loginUpdate['password_hash'] = $this->passwordHasher->hash($password);
                    Db::table('ffx_users')->where('id', (int) $user['id'])->update($loginUpdate);
                    $this->limiter->clear($rateKey);
                    return redirect($redirect);
                }
                $this->limiter->hit($rateKey, 900);
                $message = $attempts >= 10 ? '尝试次数过多，请 15 分钟后再试。' : '用户名或密码不正确。';
            }
        }
        return view(ff_theme_view('user/login'), $this->frontend->shared('会员登录') + ['redirect' => $redirect, 'message' => $message, 'csrf' => $this->csrf->get()]);
    }

    public function register(): Response
    {
        $redirect = $this->safeRedirect((string) $this->request->param('redirect', '/user/center'));
        $message = '';
        if (!$this->settings->bool('admin.register.user_register', true)) {
            return view(ff_theme_view('user/register'), $this->frontend->shared('会员注册') + ['redirect' => $redirect, 'message' => '当前网站已关闭会员注册。', 'csrf' => $this->csrf->get(), 'registrationClosed' => 1]);
        }
        if ($this->request->isPost()) {
            if (!$this->csrf->verify($this->request->post('_token'))) {
                $message = '请求已过期，请重新提交。';
            } else {
                $username = mb_substr(trim((string) $this->request->post('user_name', '')), 0, 80);
                $password = (string) $this->request->post('user_pwd', '');
                $email = mb_substr(trim((string) $this->request->post('user_email', '')), 0, 255);
                $minName = $this->settings->int('admin.register.username_min', 3, 2, 30);
                $maxName = $this->settings->int('admin.register.username_max', 30, $minName, 80);
                $minPassword = $this->settings->int('admin.register.password_min', 8, 6, 72);
                $rateKey = 'rate:user-register:' . hash('sha256', (string) $this->request->ip());
                if ($this->limiter->attempts($rateKey) > 0) {
                    $message = '注册过于频繁，请稍后再试。';
                } elseif (!preg_match('/^[\p{L}\p{N}_-]{' . $minName . ',' . $maxName . '}$/u', $username)) {
                    $message = '用户名需为 ' . $minName . '–' . $maxName . ' 个字符，只能使用文字、数字、下划线或短横线。';
                } elseif (strlen($password) < $minPassword || strlen($password) > 200) {
                    $message = '密码至少 ' . $minPassword . ' 个字符。';
                } elseif ($this->settings->bool('admin.register.email_required') && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = '请填写有效邮箱地址。';
                } elseif (Db::table('ffx_users')->where('username', $username)->count() > 0) {
                    $message = '该用户名已存在。';
                } elseif ($email !== '' && Db::table('ffx_users')->where('email', $email)->count() > 0) {
                    $message = '该邮箱已经注册。';
                } else {
                    $now = gmdate('Y-m-d H:i:s');
                    $vipDays = $this->settings->int('admin.register.user_register_vipday', 0, 0, 36500);
                    $id = Db::table('ffx_users')->insertGetId([
                        'username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'email' => $email !== '' ? $email : null,
                        'points' => $this->settings->int('admin.register.user_register_score', 0, 0),
                        'group_key' => mb_substr($this->settings->string('admin.register.default_group', 'member'), 0, 50),
                        'status' => $this->settings->bool('admin.register.user_register_check') ? 'disabled' : 'active',
                        'expires_at' => $vipDays > 0 ? gmdate('Y-m-d H:i:s', time() + ($vipDays * 86400)) : null,
                        'last_login_ip' => (string) $this->request->ip(), 'last_login_at' => $now,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $this->limiter->hit($rateKey, $this->settings->int('admin.register.user_register_second', 60, 1, 86400));
                    if ($this->settings->bool('admin.register.user_register_check')) {
                        $message = '注册成功，请等待账号审核或完成邮箱验证。';
                    } else {
                        Session::regenerate(true);
                        Session::set('user_id', $id);
                        Session::set('user_name', $username);
                        return redirect($redirect);
                    }
                }
            }
        }
        return view(ff_theme_view('user/register'), $this->frontend->shared('会员注册') + ['redirect' => $redirect, 'message' => $message, 'csrf' => $this->csrf->get()]);
    }

    public function center(): Response
    {
        $id = (int) Session::get('user_id', 0);
        $user = $id > 0 ? Db::table('ffx_users')->where('id', $id)->where('status', 'active')->whereNull('deleted_at')->find() : null;
        $records = [];
        $legacy = null;
        if ($user !== null) {
            $legacy = [
                'user_name' => $user['username'], 'user_score' => (int) $user['points'], 'user_lognum' => 0,
                'user_deadtime' => $user['expires_at'] ? strtotime((string) $user['expires_at']) : 0,
                'user_joinip' => (string) ($user['last_login_ip'] ?? ''), 'user_logip' => (string) ($user['last_login_ip'] ?? ''),
            ];
            $records = $this->watchHistory->records($id);
        }
        $favorites = [];
        if ($user !== null) {
            $rows = Db::table('ffx_favorites')->alias('f')->join(['ffx_media' => 'm'], 'm.id=f.media_id')->where('f.user_id', $id)->where('m.status', 'published')->whereNull('m.deleted_at')->order('f.created_at', 'desc')->field('m.*')->select()->toArray();
            $favorites = $this->frontend->vodList($rows);
        }
        return view(ff_theme_view('user/center'), $this->frontend->shared('会员中心') + ['user' => $legacy, 'records' => $records, 'favorites' => $favorites, 'csrf' => $this->csrf->get()]);
    }

    public function info(): Response
    {
        $id = (int) Session::get('user_id', 0);
        if ($id < 1) return json(['logged' => 0]);
        $user = Db::table('ffx_users')->where('id', $id)->where('status', 'active')->whereNull('deleted_at')->find();
        return $user === null ? json(['logged' => 0]) : json(['logged' => 1, 'user_id' => $id, 'user_name' => $user['username']]);
    }

    public function logout(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return response('请求已过期', 419);
        Session::destroy();
        return redirect('/');
    }

    public function redeem(): Response
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId < 1) return redirect('/user/login?redirect=' . rawurlencode('/payment'));
        if (!$this->csrf->verify($this->request->post('_token'))) return response('请求已过期', 419);
        $plain = strtoupper(trim((string) $this->request->post('card_number', '')));
        if ($plain === '') return redirect('/payment?message=' . rawurlencode('请输入充值卡密'));
        $hash = hash('sha256', $plain);
        $message = '';
        Db::transaction(function () use ($hash, $userId, &$message): void {
            $card = Db::table('ffx_cards')->where('card_hash', $hash)->lock(true)->find();
            if ($card === null || $card['status'] !== 'unused') { $message = '卡密无效或已使用'; return; }
            if (!empty($card['expires_at']) && strtotime((string) $card['expires_at']) < time()) { $message = '卡密已过期'; return; }
            $now = gmdate('Y-m-d H:i:s');
            Db::table('ffx_cards')->where('id', (int) $card['id'])->update(['status' => 'used', 'used_by' => $userId, 'used_at' => $now]);
            $user = Db::table('ffx_users')->where('id', $userId)->lock(true)->find();
            Db::table('ffx_users')->where('id', $userId)->update(['points' => (int) $user['points'] + (int) $card['face_value'], 'updated_at' => $now]);
            $message = '充值成功，已增加 ' . (int) $card['face_value'] . ' 积分';
        });
        return redirect('/payment?message=' . rawurlencode($message));
    }

    private function safeRedirect(string $redirect): string
    {
        $redirect = rawurldecode(trim($redirect));
        if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_contains($redirect, "\0")) return '/user/center';
        return mb_substr($redirect, 0, 1000);
    }
}
