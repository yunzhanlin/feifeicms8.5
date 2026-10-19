<?php
declare(strict_types=1);

namespace app\service;

/** Static assets only: does not initialize ThinkPHP, sessions, settings or a database. */
final class ThemeAssets
{
    private const TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
    ];

    public function __construct(private readonly ?string $directory = null) {}

    public function root(): string
    {
        $directory = $this->directory ?? dirname(__DIR__, 2) . '/view';
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || is_link($directory)) throw new \RuntimeException('主题目录无效');
        return $root;
    }

    /** @return array{path:string,type:string,size:int,mtime:int,etag:string} */
    public function resolve(string $theme, string $asset): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $theme)
            || !preg_match('~^[\pL\pN_@-][\pL\pN_.@-]*(?:/[\pL\pN_@-][\pL\pN_.@-]*)*$~uD', $asset)
            || preg_match('~\.(?:php[0-9]*|phtml|phar|tpl|html?|ini|env)(?:[./]|$)~i', $asset)) {
            throw new \RuntimeException('资源路径无效');
        }
        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) throw new \RuntimeException('不允许读取此类文件');
        $root = $this->root();
        $path = $root;
        foreach (array_merge([$theme, 'assets'], explode('/', $asset)) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new \RuntimeException('资源路径不允许软链接');
        }
        $real = realpath($path);
        $base = $root . '/' . $theme . '/assets/';
        if ($real === false || !str_starts_with($real, $base) || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException('资源不存在');
        }
        $hash = hash_file('sha256', $real);
        if ($hash === false) throw new \RuntimeException('资源不可读');
        return ['path' => $real, 'type' => self::TYPES[$extension], 'size' => (int) filesize($real),
            'mtime' => (int) filemtime($real), 'etag' => '"' . $hash . '"'];
    }

    public function url(string $theme, string $asset): string
    {
        static $versions = [];
        $key = $this->root() . '/' . $theme . '/' . $asset;
        $version = $versions[$key] ??= substr(trim($this->resolve($theme, $asset)['etag'], '"'), 0, 16);
        return '/theme-assets/' . rawurlencode($theme) . '/' . implode('/', array_map('rawurlencode', explode('/', $asset))) . '?v=' . $version;
    }

    /** Old built-in asset URLs remain readable, without retaining duplicate files. */
    public static function legacy(string $path): ?array
    {
        $exact = [
            '/mxstatic/image/logo.png' => ['mxone', 'images/legacy-logo.png'],
            '/static/admin.css' => ['admin', 'css/admin.css'], '/static/admin.js' => ['admin', 'js/admin.js'],
            '/static/install.css' => ['install', 'css/install.css'],
            '/static/css/system.css' => ['mxone', 'css/system.css'], '/static/js/mxone.js' => ['mxone', 'js/app.js'],
            '/static/js/player.js' => ['shared', 'player/player.js'],
            '/static/app.css' => ['shared', 'css/app.css'], '/static/content.css' => ['shared', 'css/content.css'],
            '/static/frontend-v2.css' => ['shared', 'css/frontend-v2.css'],
        ];
        if (isset($exact[$path])) return $exact[$path];
        foreach (['/mxstatic/image/' => ['mxone', 'images/'], '/mxstatic/picture/' => ['mxone', 'images/'],
            '/mxstatic/font/' => ['mxone', 'fonts/'], '/mxstatic/' => ['mxone', ''], '/static/admin-legacy/' => ['admin', 'images/'],
            '/static/images/' => ['shared', 'images/'], '/player/vendor/' => ['shared', 'player/vendor/'],
            '/legacy/' => ['shared', 'legacy/']] as $prefix => [$theme, $directory]) {
            if (str_starts_with($path, $prefix)) return [$theme, $directory . substr($path, strlen($prefix))];
        }
        return null;
    }

    /** Return true only when the request belongs to the static theme-asset endpoint. */
    public static function dispatch(string $project): bool
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if ($path === '/index.php' && isset($_GET['s']) && is_string($_GET['s'])) $path = explode('?', $_GET['s'], 2)[0];
        $path = rawurldecode($path);
        $target = self::legacy($path);
        if (str_starts_with($path, '/theme-assets/')) {
            $parts = explode('/', substr($path, strlen('/theme-assets/')), 2);
            $target = count($parts) === 2 ? $parts : ['', ''];
        } elseif ($target === null) {
            return false;
        }
        header('X-Content-Type-Options: nosniff');
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            http_response_code(405); header('Allow: GET, HEAD'); header('Cache-Control: no-store');
            return true;
        }
        try { $file = (new self($project . '/view'))->resolve($target[0], $target[1]); }
        catch (\Throwable) {
            http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo '资源不存在';
            return true;
        }
        header('Content-Type: ' . $file['type']);
        header('ETag: ' . $file['etag']);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file['mtime']) . ' GMT');
        $version = substr(trim($file['etag'], '"'), 0, 16);
        header('Cache-Control: ' . (isset($_GET['v']) && $_GET['v'] === $version ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate'));
        // SVG may be displayed as an image, but must not run scripts as a document.
        if (str_starts_with($file['type'], 'image/svg+xml')) header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($ifNoneMatch !== '' && ($ifNoneMatch === '*' || in_array($file['etag'], array_map(static fn ($tag) => preg_replace('/^W\//', '', trim($tag)), explode(',', $ifNoneMatch)), true))) {
            http_response_code(304); return true;
        }
        header('Content-Length: ' . $file['size']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file['path']);
        return true;
    }
}
