<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\LegacyRewriteRules;
use app\service\SiteSettings;
use app\service\SmtpMailer;
use app\service\ThemeRegistry;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Settings extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly AuditLogger $audit,
        private readonly LegacyRewriteRules $rewriteRules,
        private readonly ThemeRegistry $themes,
        private readonly SiteSettings $siteSettings,
        private readonly SmtpMailer $mailer,
    )
    {
        parent::__construct($app);
    }

    public function index(string $section): Response
    {
        $definition = $this->runtimeDefinition($section);
        $values = [];
        foreach ($definition['groups'] as $fields) {
            foreach ($fields as $key => $field) {
                $stored = Db::table('ffx_site_settings')->where('setting_key', 'admin.' . $section . '.' . $key)->value('setting_value');
                if ($section === 'rewrite' && $key === 'url_html_suffix' && $stored === null) {
                    $stored = Db::table('ffx_site_settings')->where('setting_key', 'admin.rewrite.html_suffix')->value('setting_value');
                }
                $decoded = is_string($stored) ? json_decode($stored, true) : $stored;
                $value = is_scalar($decoded) ? (string) $decoded : (string) $field['default'];
                if ($section === 'rewrite' && $key === 'url_html_suffix' && $value !== '' && !str_starts_with($value, '.')) $value = '.' . $value;
                $values[$key] = !empty($field['secret']) && $value !== '' ? '••••••••' : $value;
            }
        }
        return $this->render($section, $definition, $values);
    }

    public function previewRewrite(): Response
    {
        $this->guardCsrf();
        $definition = $this->runtimeDefinition('rewrite');
        $values = [];
        foreach ($definition['groups'] as $fields) foreach ($fields as $key => $field) {
            $values[$key] = mb_substr(trim((string) $this->request->post($key, $field['default'])), 0, $key === 'rewrite_route' ? 20000 : 2000);
        }
        $test = ['action' => trim((string) $this->request->post('test_action', '')), 'path' => trim((string) $this->request->post('test_path', '')), 'rewrite' => '', 'resolve' => ''];
        try {
            $preview = $this->rewriteRules->preview($values['rewrite_route'] ?? '');
            if ($test['action'] !== '') $test['rewrite'] = $this->rewriteRules->rewrite($test['action'], $values['rewrite_route'] ?? '') ?? '未匹配';
            if ($test['path'] !== '') $test['resolve'] = $this->rewriteRules->resolve($test['path'], $values['rewrite_route'] ?? '') ?? '未匹配';
            return $this->render('rewrite', $definition, $values, $preview, $test);
        } catch (\InvalidArgumentException $exception) {
            return $this->render('rewrite', $definition, $values, [], $test, $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $definition @param array<string,string> $values @param list<array<string,mixed>> $rewritePreview @param array<string,string> $rewriteTest */
    private function render(string $section, array $definition, array $values, array $rewritePreview = [], array $rewriteTest = [], string $previewError = ''): Response
    {
        $settingGroups = [];
        foreach ($definition['groups'] as $label => $fields) {
            $settingGroups[] = ['id' => 'settings-group-' . (count($settingGroups) + 1), 'label' => $label, 'fields' => $fields];
        }
        return view('/admin/settings/index', [
            'section' => $section, 'definition' => $definition, 'values' => $values,
            'settingGroups' => $settingGroups,
            'sections' => $this->sections(), 'csrf' => $this->csrf->get(),
            'saved' => (string) $this->request->get('saved', '') === '1',
            'tested' => (string) $this->request->get('tested', '') === '1',
            'error' => $previewError !== '' ? mb_substr($previewError, 0, 300) : mb_substr(trim((string) $this->request->get('error', '')), 0, 300),
            'rewritePreview' => $rewritePreview, 'rewriteTest' => $rewriteTest,
        ]);
    }

    public function update(string $section): Response
    {
        $this->guardCsrf();
        $definition = $this->runtimeDefinition($section);
        $compiledRewrite = null;
        $forceRouterOff = false;
        if ($section === 'rewrite') {
            $forceRouterOff = trim((string) $this->request->post('rewrite_route', '')) === '';
            try {
                $compiledRewrite = $this->rewriteRules->compiled(trim((string) $this->request->post('rewrite_route', '')));
            } catch (\InvalidArgumentException $exception) {
                return redirect('/admin/settings/rewrite?error=' . rawurlencode($exception->getMessage()));
            }
        }
        $before = [];
        $after = [];
        foreach ($definition['groups'] as $fields) {
            foreach ($fields as $key => $field) {
                if ($field['type'] === 'readonly') continue;
                $settingKey = 'admin.' . $section . '.' . $key;
                $stored = Db::table('ffx_site_settings')->where('setting_key', $settingKey)->value('setting_value');
                $before[$key] = $stored;
                $value = trim((string) $this->request->post($key, $field['default']));
                if ($section === 'rewrite' && $key === 'url_router_on' && $forceRouterOff) $value = '0';
                if (!empty($field['secret']) && ($value === '' || $value === '••••••••')) continue;
                if ($field['type'] === 'boolean') {
                    $value = $value === '1' ? '1' : '0';
                } elseif ($field['type'] === 'number') {
                    $value = (string) max(0, (int) $value);
                } elseif (in_array($field['type'], ['select', 'radio'], true) && !array_key_exists($value, (array) ($field['options'] ?? []))) {
                    $value = (string) $field['default'];
                } else {
                    $value = mb_substr($value, 0, $field['type'] === 'textarea' ? 20000 : 2000);
                }
                $after[$key] = !empty($field['secret']) ? '[updated]' : $value;
                $payload = [
                    'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_public' => !empty($field['public']) ? 1 : 0,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ];
                if ($stored === null) Db::table('ffx_site_settings')->insert(['setting_key' => $settingKey] + $payload);
                else Db::table('ffx_site_settings')->where('setting_key', $settingKey)->update($payload);
            }
        }
        if ($section === 'rewrite' && is_array($compiledRewrite)) {
            foreach (['url_rewrite_rules' => $compiledRewrite['rewrite_rules'], 'url_route_rules' => $compiledRewrite['route_rules']] as $key => $value) {
                $settingKey = 'admin.rewrite.' . $key;
                $payload = [
                    'setting_value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'is_public' => 0,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ];
                if (Db::table('ffx_site_settings')->where('setting_key', $settingKey)->value('setting_key') === null) {
                    Db::table('ffx_site_settings')->insert(['setting_key' => $settingKey] + $payload);
                } else {
                    Db::table('ffx_site_settings')->where('setting_key', $settingKey)->update($payload);
                }
            }
        }
        $this->audit->record('settings.update', 'settings', $section, $before, $after);
        return redirect('/admin/settings/' . $section . '?saved=1');
    }

    public function testEmail(): Response
    {
        $this->guardCsrf();
        $recipient = trim((string) $this->request->post('recipient', ''));
        try {
            $this->mailer->sendTest($this->siteSettings->section('email'), $recipient);
        } catch (\Throwable $exception) {
            return redirect('/admin/settings/email?error=' . rawurlencode(mb_substr($exception->getMessage(), 0, 260)));
        }
        $this->audit->record('settings.email_test', 'settings', 'email', null, ['recipient' => $recipient]);
        return redirect('/admin/settings/email?tested=1');
    }

    private function definition(string $section): array
    {
        $sections = $this->sections();
        if (!isset($sections[$section])) {
            throw new HttpException(404, '配置分组不存在');
        }
        return $sections[$section];
    }

    private function runtimeDefinition(string $section): array
    {
        $definition = $this->definition($section);
        if ($section !== 'base') return $definition;
        $options = $this->themes->options();
        if ($options === []) $options = ['mxone' => 'MXOne'];
        foreach (['default_theme', 'default_theme_m'] as $key) {
            $definition['groups']['基本配置'][$key]['options'] = $options;
        }
        return $definition;
    }

    private function sections(): array
    {
        $sections = config('admin_settings');
        return is_array($sections) ? $sections : [];
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
