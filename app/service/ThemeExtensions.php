<?php
declare(strict_types=1);

namespace app\service;

/** Developer-owned theme extensions. Never writable through the template editor. */
final class ThemeExtensions
{
    /** @var array<string,array<string,callable>> */
    private array $loaded = [];

    public function __construct(private readonly ?string $directory = null) {}

    public function call(string $theme, string $name, mixed ...$arguments): mixed
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $theme) || !preg_match('/^[a-z][a-z0-9_]*$/D', $name)) throw new \InvalidArgumentException('模板扩展名称无效');
        $root = (new ThemeAssets($this->directory))->root();
        $key = $root . '/' . $theme;
        if (!isset($this->loaded[$key])) {
            $file = $root;
            foreach ([$theme, 'extensions', 'functions.php'] as $part) {
                $file .= '/' . $part;
                if (is_link($file)) throw new \RuntimeException('模板扩展不能使用软链接');
            }
            if (!is_file($file)) throw new \RuntimeException('模板未提供 PHP 扩展');
            $extensions = (static fn (string $path) => require $path)($file);
            if (!is_array($extensions)) throw new \RuntimeException('模板扩展必须返回命名函数数组');
            foreach ($extensions as $extension => $callback) {
                if (!is_string($extension) || !preg_match('/^[a-z][a-z0-9_]*$/D', $extension) || !is_callable($callback)) throw new \RuntimeException('模板扩展定义无效');
            }
            $this->loaded[$key] = $extensions;
        }
        if (!isset($this->loaded[$key][$name])) throw new \InvalidArgumentException('模板扩展方法不存在');
        return ($this->loaded[$key][$name])(...$arguments);
    }
}
