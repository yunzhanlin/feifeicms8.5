<?php
declare(strict_types=1);

namespace app\service;

/** Discovers complete ThinkPHP 8 frontend themes and resolves the active view. */
final class ThemeRegistry
{
    private const REQUIRED_TEMPLATES = [
        'index/index.tpl',
        'common/head.tpl',
        'common/header.tpl',
        'common/footer.tpl',
        'vod/detail.tpl',
        'vod/play.tpl',
        'vod/type.tpl',
        'search/index.tpl',
    ];

    public function __construct(private readonly SiteSettings $settings) {}

    private ?string $renderedTheme = null;

    public function current(): string
    {
        if ($this->renderedTheme !== null) return $this->renderedTheme;
        $options = $this->options();
        $setting = $this->isMobileRequest() ? 'default_theme_m' : 'default_theme';
        $theme = $this->settings->string('admin.base.' . $setting, 'mxone');
        return isset($options[$theme]) ? $theme : 'mxone';
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $root = (new TemplateFiles())->root();
        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $options = [];
        foreach ($directories as $directory) {
            $key = basename($directory);
            if (!preg_match('/^[A-Za-z0-9_-]+$/D', $key)) continue;
            if (in_array(strtolower($key), ['admin', 'install', 'shared'], true) || is_link($directory) || !$this->isComplete($directory)) continue;
            $options[$key] = match (strtolower($key)) {
                'mxone' => 'MXOne',
                default => strtoupper($key),
            };
        }
        ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    public function template(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if (!preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $relative)) return 'mxone/index/index';
        $options = $this->options();
        $setting = $this->isMobileRequest() ? 'default_theme_m' : 'default_theme';
        $theme = $this->settings->string('admin.base.' . $setting, 'mxone');
        if (!isset($options[$theme])) $theme = isset($options['mxone']) ? 'mxone' : (string) array_key_first($options);
        $files = new TemplateFiles();
        foreach (array_unique([$theme, 'mxone']) as $candidate) {
            if ($candidate === '') continue;
            try {
                $file = $files->file($candidate . '/' . $relative . '.tpl');
                $this->renderedTheme = $candidate;
                \think\facade\View::assign('themeName', $candidate);
                \think\facade\View::engine()->config(['view_path' => $files->root() . '/' . $candidate . '/', 'view_suffix' => 'tpl']);
                return $file;
            } catch (\think\exception\HttpException) {}
        }
        throw new \think\exception\HttpException(404, '模板不存在');
    }

    private function isComplete(string $directory): bool
    {
        foreach (self::REQUIRED_TEMPLATES as $template) {
            $path = $directory;
            foreach (explode('/', $template) as $part) {
                $path .= DIRECTORY_SEPARATOR . $part;
                if (is_link($path)) return false;
            }
            if (!is_file($path)) return false;
        }
        return true;
    }

    private function isMobileRequest(): bool
    {
        $agent = strtolower((string) request()->header('user-agent', ''));
        return $agent !== '' && preg_match('/android|iphone|ipad|ipod|mobile|windows phone/', $agent) === 1;
    }
}
