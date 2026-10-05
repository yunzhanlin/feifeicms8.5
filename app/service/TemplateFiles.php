<?php
declare(strict_types=1);

namespace app\service;

use think\exception\HttpException;

/** Private template source; public CSS/JS/images belong under public/. */
final class TemplateFiles
{
    public function __construct(private readonly ?string $directory = null) {}

    public function root(): string
    {
        $directory = $this->directory ?? root_path() . 'view';
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || is_link($directory)) throw new HttpException(500, '模板目录不存在或为软链接');
        if ($this->directory === null) {
            $public = realpath(public_path());
            if ($public !== false && ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR))) {
                throw new HttpException(500, '模板目录必须位于 public 网站运行目录之外');
            }
        }
        return $root;
    }

    public function normalize(string $relative, bool $allowEmpty = false): string
    {
        $relative = trim($relative);
        // Do not decode a second time: request parameters are already decoded.
        // Neither dot segments, hidden files nor platform-specific separators
        // are valid template names.
        if ($relative === '' && $allowEmpty) return '';
        if (!preg_match('#^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D', $relative)) {
            throw new HttpException(422, '模板路径无效');
        }
        $theme = strtolower(explode('/', $relative, 2)[0]);
        if (in_array($theme, ['admin', 'install'], true)) throw new HttpException(403, '系统模板不允许在此编辑');
        return $relative;
    }

    public function file(string $relative, bool $create = false): string
    {
        $relative = $this->normalize($relative);
        if (!str_contains($relative, '/') || !str_ends_with($relative, '.html')) throw new HttpException(422, '模板目录仅允许 HTML 模板；静态资源请放在 public 目录');
        $path = $this->resolve($relative);
        if (!$create && !is_file($path)) throw new HttpException(404, '模板文件不存在');
        if ($create && (file_exists($path) || is_link($path))) throw new HttpException(409, '模板文件已存在');
        $theme = explode('/', $relative, 2)[0];
        if (!is_dir($this->resolve($theme))) throw new HttpException(404, '模板主题不存在');
        return $path;
    }

    public function directory(string $relative): string
    {
        $relative = $this->normalize($relative, true);
        $path = $this->resolve($relative);
        if (!is_dir($path)) throw new HttpException(404, '模板目录不存在');
        return $path;
    }

    private function resolve(string $relative): string
    {
        $root = $this->root();
        $path = $root;
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) throw new HttpException(403, '模板路径不能包含软链接');
            if (file_exists($path)) {
                $resolved = realpath($path);
                if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) throw new HttpException(403, '模板路径越界');
            }
        }
        return $path;
    }
}
