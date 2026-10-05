<?php
declare(strict_types=1);

namespace app\service;

/** Discovers complete ThinkPHP 8 frontend themes and resolves the active view. */
final class ThemeRegistry
{
    private const REQUIRED_TEMPLATES = [
        'index/index.html',
        'common/head.html',
        'common/header.html',
        'common/footer.html',
        'vod/detail.html',
        'vod/play.html',
        'vod/type.html',
        'search/index.html',
    ];

    public function __construct(private readonly SiteSettings $settings) {}

    /** @return array<string, string> */
    public function options(): array
    {
        $root = (new TemplateFiles())->root();
        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $options = [];
        foreach ($directories as $directory) {
            $key = basename($directory);
            if (in_array(strtolower($key), ['admin', 'install'], true) || is_link($directory) || !$this->isComplete($directory)) continue;
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
                $files->file($candidate . '/' . $relative . '.html');
                return $candidate . '/' . $relative;
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
