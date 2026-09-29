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
        $root = rtrim(root_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'view';
        $directories = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        $options = [];
        foreach ($directories as $directory) {
            $key = basename($directory);
            if ($key === 'admin' || !$this->isComplete($directory)) continue;
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
        if ($relative === '' || str_contains($relative, '..')) return 'mxone/index/index';
        $options = $this->options();
        $setting = $this->isMobileRequest() ? 'default_theme_m' : 'default_theme';
        $theme = $this->settings->string('admin.base.' . $setting, 'mxone');
        if (!isset($options[$theme])) $theme = isset($options['mxone']) ? 'mxone' : (string) array_key_first($options);
        $root = rtrim(root_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        if ($theme !== '' && is_file($root . $theme . DIRECTORY_SEPARATOR . $relative . '.html')) return $theme . '/' . $relative;
        if (is_file($root . 'mxone' . DIRECTORY_SEPARATOR . $relative . '.html')) return 'mxone/' . $relative;
        return $relative;
    }

    private function isComplete(string $directory): bool
    {
        foreach (self::REQUIRED_TEMPLATES as $template) {
            if (!is_file($directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $template))) return false;
        }
        return true;
    }

    private function isMobileRequest(): bool
    {
        $agent = strtolower((string) request()->header('user-agent', ''));
        return $agent !== '' && preg_match('/android|iphone|ipad|ipod|mobile|windows phone/', $agent) === 1;
    }
}
