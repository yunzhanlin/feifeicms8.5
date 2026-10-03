<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AdminAuthenticator;
use app\service\CsrfToken;
use app\service\SiteSettings;
use app\service\RequestRateLimiter;
use think\facade\Session;
use think\Response;

final class Login extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly AdminAuthenticator $authenticator,
        private readonly CsrfToken $csrf,
        private readonly SiteSettings $settings,
        private readonly RequestRateLimiter $limiter,
    ) {
        parent::__construct($app);
    }

    public function index(): Response
    {
        if ((int) Session::get('admin_id', 0) > 0) {
            return redirect('/admin');
        }
        return view('/admin/login', ['csrf' => $this->csrf->get(), 'error' => '', 'siteName' => $this->siteName()]);
    }

    public function submit(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return view('/admin/login', ['csrf' => $this->csrf->get(), 'error' => '请求已过期，请重新提交。', 'siteName' => $this->siteName()], 419);
        }

        $ip = (string) $this->request->ip();
        $rateKey = 'rate:admin-login:' . hash('sha256', $ip);
        $attempts = $this->limiter->attempts($rateKey);
        if ($attempts >= 8) {
            return view('/admin/login', ['csrf' => $this->csrf->get(), 'error' => '尝试次数过多，请 15 分钟后再试。', 'siteName' => $this->siteName()], 429);
        }

        $username = mb_substr(trim((string) $this->request->post('username', '')), 0, 50);
        $password = (string) $this->request->post('password', '');
        $admin = $this->authenticator->attempt($username, $password, $ip);
        if ($admin === null) {
            $this->limiter->hit($rateKey, 900);
            return view('/admin/login', ['csrf' => $this->csrf->get(), 'error' => '用户名或密码不正确。', 'siteName' => $this->siteName()], 422);
        }

        Session::regenerate(true);
        Session::set('admin_id', (int) $admin->id);
        Session::set('admin_name', (string) $admin->username);
        Session::set('update_notice_pending', true);
        $this->limiter->clear($rateKey);
        return redirect('/admin');
    }

    public function logout(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return response('请求已过期', 419);
        }
        Session::destroy();
        return redirect('/admin.php');
    }

    private function siteName(): string
    {
        return $this->settings->string('admin.base.site_name', (string) config('feifei.site_name'));
    }
}
