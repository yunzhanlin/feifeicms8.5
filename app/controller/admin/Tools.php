<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SiteSettings;
use app\service\SafeRemoteUrl;
use app\service\ThemeRegistry;
use GuzzleHttp\Client;
use think\exception\HttpException;
use think\facade\Cache;
use think\facade\Db;
use think\Response;
use Throwable;

final class Tools extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SiteSettings $settings, private readonly SafeRemoteUrl $safeUrl, private readonly ThemeRegistry $themes) { parent::__construct($app); }

    public function cache(): Response
    {
        $cache = ['driver' => (string) config('cache.default'), 'ok' => false, 'keys' => null, 'memory' => '', 'peak' => ''];
        try {
            Cache::set('admin:tools:cache-probe', 'ok', 10);
            $cache['ok'] = Cache::get('admin:tools:cache-probe') === 'ok';
            Cache::delete('admin:tools:cache-probe');
            if ($cache['driver'] === 'redis') {
                $settings = (array) config('cache.stores.redis');
                $client = new \Predis\Client([
                    'scheme' => 'tcp', 'host' => (string) ($settings['host'] ?? '127.0.0.1'),
                    'port' => (int) ($settings['port'] ?? 6379), 'database' => (int) ($settings['select'] ?? 0),
                    'password' => ($settings['password'] ?? '') !== '' ? (string) $settings['password'] : null,
                    'timeout' => (float) ($settings['timeout'] ?? 2),
                ]);
                $info = $client->info('memory');
                $memory = (array) ($info['Memory'] ?? $info['memory'] ?? $info);
                $cache['keys'] = (int) $client->dbsize();
                $cache['memory'] = (string) ($memory['used_memory_human'] ?? '');
                $cache['peak'] = (string) ($memory['used_memory_peak_human'] ?? '');
            }
        } catch (Throwable $exception) {
            $cache['error'] = $exception->getMessage();
        }

        return view('/admin/tools/cache', [
            'cache' => $cache,
            'templateStats' => $this->directoryStats(runtime_path() . 'temp'),
            'dataStats' => $this->directoryStats(runtime_path() . 'cache'),
            'pageStats' => $this->directoryStats(public_path() . 'generated'),
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function version(): Response
    {
        return view('/admin/tools/version', [
            'versions' => $this->versionDetails(),
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function checkVersion(): Response
    {
        $this->guardCsrf();
        $versions = $this->versionDetails();
        $ok = $versions['composer'] && $versions['schema'] >= 10 && version_compare($versions['php'], '8.2.0', '>=');
        $this->audit->record('tools.version_check', 'system', 'version', null, $versions);
        return redirect('/admin/tools/version?message=' . rawurlencode($ok ? '框架、PHP 和数据库迁移版本检查通过' : '检查发现异常，请查看版本列表'));
    }

    public function clearToolCache(): Response
    {
        $this->guardCsrf();
        $scope = (string) $this->request->post('scope', 'all');
        $allowed = ['templates', 'data', 'page', 'today_video', 'today_article', 'today_topic', 'all'];
        if (!in_array($scope, $allowed, true)) throw new HttpException(422, '缓存类型无效');

        $deleted = 0;
        if (in_array($scope, ['templates', 'all'], true)) $deleted += $this->clearDirectory(runtime_path() . 'temp');
        if (in_array($scope, ['page', 'all'], true)) $deleted += $this->clearDirectory(public_path() . 'generated');
        if (in_array($scope, ['data', 'today_video', 'today_article', 'today_topic', 'all'], true)) Cache::clear();
        if (function_exists('opcache_reset')) @opcache_reset();
        clearstatcache(true);

        $labels = [
            'templates' => '系统编译与模板缓存', 'data' => '全站数据缓存', 'page' => '页面生成文件',
            'today_video' => '今日更新视频缓存', 'today_article' => '今日更新文章缓存',
            'today_topic' => '今日更新专题缓存', 'all' => '全部站点缓存',
        ];
        $this->audit->record('tools.cache_clear', 'cache', $scope, null, ['deleted_files' => $deleted]);
        return redirect('/admin/tools/cache?message=' . rawurlencode($labels[$scope] . '已清理，删除 ' . $deleted . ' 个文件'));
    }

    public function templates(): Response
    {
        $selected = trim((string) $this->request->get('file', ''));
        if ($selected !== '') {
            $path = $this->templatePath($selected);
            $content = file_get_contents($path) ?: '';
            $directory = dirname($this->normalizeTemplateRelative($selected));
            if ($directory === '.') $directory = '';
            return view('/admin/tools/templates', [
                'mode' => 'edit',
                'selected' => $this->normalizeTemplateRelative($selected),
                'currentPath' => $directory,
                // ThinkPHP output is escaped by default. Encode once here and render raw in
                // the textarea so source code is displayed as source, not as &quot;/&lt;.
                'editorContent' => htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'csrf' => $this->csrf->get(),
            ]);
        }

        $currentPath = $this->normalizeTemplateRelative((string) $this->request->get('path', ''), true);
        $directory = $this->templateDirectoryPath($currentPath);
        $entries = [];

        // The top level is a theme selector, not a raw view/ directory browser.
        // Shared fallback views such as view/vod, view/news and view/layout are
        // implementation details; only complete themes discovered by the same
        // registry used by the frontend theme selector belong here.
        $items = [];
        if ($currentPath === '') {
            foreach (array_keys($this->themes->options()) as $theme) {
                $themePath = $directory . DIRECTORY_SEPARATOR . $theme;
                if (is_dir($themePath) && !is_link($themePath)) $items[] = new \SplFileInfo($themePath);
            }
        } else {
            foreach (new \DirectoryIterator($directory) as $item) {
                // DirectoryIterator reuses the same mutable iterator object. Store an
                // immutable SplFileInfo snapshot or every row becomes the final item.
                if (!$item->isDot() && !$item->isLink()) $items[] = new \SplFileInfo($item->getPathname());
            }
        }

        foreach ($items as $item) {
            $relative = ltrim($currentPath . '/' . $item->getFilename(), '/');
            $isDirectory = $item->isDir();
            $extension = strtolower($item->getExtension());
            $entries[] = [
                'name' => $item->getFilename(),
                'path' => $relative,
                'is_dir' => $isDirectory,
                'icon' => $isDirectory ? 'folder' : (in_array($extension, ['html', 'htm', 'css', 'js', 'jpg', 'gif'], true) ? $extension : 'other'),
                'editable' => !$isDirectory && in_array($extension, $this->templateEditableExtensions(), true),
                'description' => $isDirectory ? '文件夹' : $this->templateDescription($relative),
                'size' => $this->formatFileSize($isDirectory ? $this->directoryStats($item->getPathname())['bytes'] : $item->getSize()),
                'modified' => date('Y-m-d H:i:s', $item->getMTime()),
            ];
        }
        usort($entries, static fn (array $left, array $right): int => $right['modified'] <=> $left['modified']);
        $parentPath = $currentPath === '' ? '' : dirname($currentPath);
        if ($parentPath === '.') $parentPath = '';

        return view('/admin/tools/templates', [
            'mode' => 'browse',
            'entries' => $entries,
            'currentPath' => $currentPath,
            'parentPath' => $parentPath,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function saveTemplate(): Response
    {
        $this->guardCsrf();
        $file = (string) $this->request->post('file', '');
        $path = $this->templatePath($file);
        $before = file_get_contents($path);
        $content = (string) $this->request->post('content', '');
        if (strlen($content) > 2_000_000) throw new HttpException(422, '模板文件过大');
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new HttpException(500, '模板保存失败');
        $this->audit->record('template.update', 'template', $file, ['sha256' => hash('sha256', (string) $before)], ['sha256' => hash('sha256', $content)]);
        $directory = dirname($this->normalizeTemplateRelative($file));
        return redirect('/admin/tools/templates' . ($directory === '.' ? '' : '?path=' . rawurlencode($directory)));
    }

    public function createTemplate(): Response
    {
        $this->guardCsrf();
        $directory = $this->normalizeTemplateRelative((string) $this->request->post('path', ''), true);
        $name = trim((string) $this->request->post('file', ''));
        $file = ltrim($directory . '/' . $name, '/');
        $path = $this->newTemplatePath($file);
        if (file_exists($path)) throw new HttpException(409, '模板文件已存在');
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new HttpException(500, '模板目录创建失败');
        $content = (string) $this->request->post('content', "{include file=\"common/header\" /}\n{include file=\"common/footer\" /}\n");
        if ($content === '' || strlen($content) > 2_000_000) throw new HttpException(422, '模板内容不能为空且不能超过 2MB');
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new HttpException(500, '模板创建失败');
        chmod($path, 0644);
        $this->audit->record('template.create', 'template', $file, null, ['sha256' => hash('sha256', $content)]);
        return redirect('/admin/tools/templates?path=' . rawurlencode($directory));
    }

    public function deleteTemplate(): Response
    {
        $this->guardCsrf();
        $file = (string) $this->request->post('file', '');
        $path = $this->templatePath($file);
        if (!unlink($path)) throw new HttpException(500, '模板删除失败');
        $this->audit->record('template.delete', 'template', $file);
        $directory = dirname($this->normalizeTemplateRelative($file));
        return redirect('/admin/tools/templates' . ($directory === '.' ? '' : '?path=' . rawurlencode($directory)));
    }

    public function uploads(): Response
    {
        $root = $this->uploadRoot();
        if (!is_dir($root)) mkdir($root, 0755, true);
        chmod($root, 0755);
        $files = [];
        foreach (glob($root . '/*') ?: [] as $file) {
            if (is_file($file)) $files[] = ['name' => basename($file), 'url' => $this->uploadUrl(basename($file)), 'size' => filesize($file), 'time' => filemtime($file)];
        }
        usort($files, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        return view('/admin/tools/uploads', ['files' => $files, 'csrf' => $this->csrf->get()]);
    }

    public function upload(): Response
    {
        $this->guardCsrf();
        $file = $this->request->file('file');
        if ($file === null || !$file->isValid()) throw new HttpException(422, '请选择有效文件');
        $size = $file->getSize();
        $maxMb = $this->settings->int('admin.files.max_size_mb', 20, 1, 200);
        if ($size > $maxMb * 1024 * 1024) throw new HttpException(422, '文件不能超过 ' . $maxMb . 'MB');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime])) throw new HttpException(422, '仅允许 JPG、PNG、WebP 或 GIF 图片');
        $name = gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
        $root = $this->uploadRoot();
        if (!is_dir($root)) mkdir($root, 0755, true);
        chmod($root, 0755);
        $file->move($root, $name);
        chmod($root . DIRECTORY_SEPARATOR . $name, 0644);
        $this->audit->record('upload.create', 'upload', $name, null, ['mime' => $mime, 'size' => $size]);
        return redirect('/admin/tools/uploads');
    }

    public function fetchUpload(): Response
    {
        $this->guardCsrf();
        $url = trim((string) $this->request->post('url', ''));
        try {
            $resolved = $this->safeUrl->resolve($url);
        } catch (Throwable $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        if (str_contains($resolved['ip'], ':')) throw new HttpException(422, '远程图片下载暂不支持仅 IPv6 的地址');
        $maxBytes = $this->settings->int('admin.files.max_size_mb', 20, 1, 200) * 1024 * 1024;
        $temporary = tempnam(runtime_path(), 'remote-image-');
        if ($temporary === false) throw new HttpException(500, '无法创建下载临时文件');
        try {
            $options = [
                'sink' => $temporary, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, 'connect_timeout' => 8,
                'on_headers' => static function ($response) use ($maxBytes): void {
                    $length = (int) $response->getHeaderLine('Content-Length');
                    if ($length > $maxBytes) throw new \RuntimeException('远程图片超过附件大小限制');
                },
                'progress' => static function (int $downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloadTotal > $maxBytes || $downloaded > $maxBytes) throw new \RuntimeException('远程图片超过附件大小限制');
                },
            ];
            if (defined('CURLOPT_RESOLVE')) $options['curl'] = [CURLOPT_RESOLVE => [$resolved['host'] . ':' . $resolved['port'] . ':' . $resolved['ip']]];
            $response = (new Client())->get($resolved['url'], $options);
            $size = filesize($temporary) ?: 0;
            if ($response->getStatusCode() !== 200) throw new HttpException(422, '远程图片返回 HTTP ' . $response->getStatusCode());
            if ($size < 1 || $size > $maxBytes) throw new HttpException(422, '远程图片为空或超过附件大小限制');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($extensions[$mime])) throw new HttpException(422, '远程地址不是支持的图片格式');
            $name = 'remote-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
            $root = $this->uploadRoot();
            if (!is_dir($root)) mkdir($root, 0755, true);
            if (!rename($temporary, $root . DIRECTORY_SEPARATOR . $name)) throw new HttpException(500, '远程图片保存失败');
            chmod($root . DIRECTORY_SEPARATOR . $name, 0644);
            $temporary = '';
            $this->audit->record('upload.remote_fetch', 'upload', $name, null, ['host' => $resolved['host'], 'mime' => $mime, 'size' => $size]);
            return redirect('/admin/tools/uploads');
        } finally {
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
        }
    }

    public function thumbnailUpload(string $name): Response
    {
        $this->guardCsrf();
        if (!extension_loaded('gd')) throw new HttpException(422, '服务器未安装 GD 扩展');
        $path = $this->existingUploadPath($name);
        $info = @getimagesize($path);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) throw new HttpException(422, '附件不是有效图片');
        $mime = (string) ($info['mime'] ?? '');
        $loaders = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp', 'image/gif' => 'imagecreatefromgif'];
        if (!isset($loaders[$mime]) || !function_exists($loaders[$mime])) throw new HttpException(422, '当前 GD 不支持该图片格式');
        $source = @$loaders[$mime]($path);
        if ($source === false) throw new HttpException(422, '图片读取失败');
        $width = $this->settings->int('admin.files.upload_thumb_w', 300, 16, 4000);
        $height = $this->settings->int('admin.files.upload_thumb_h', 420, 16, 4000);
        $mode = $this->settings->int('admin.files.upload_thumb', 1, 0, 2);
        if ($mode === 0) $mode = 1;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false); imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127); imagefill($canvas, 0, 0, $transparent);
        $sourceW = (int) $info[0]; $sourceH = (int) $info[1];
        if ($mode === 2) {
            $scale = max($width / $sourceW, $height / $sourceH);
            $copyW = (int) round($width / $scale); $copyH = (int) round($height / $scale);
            $sourceX = max(0, (int) floor(($sourceW - $copyW) / 2)); $sourceY = max(0, (int) floor(($sourceH - $copyH) / 2));
            imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, $width, $height, $copyW, $copyH);
        } else {
            $scale = min($width / $sourceW, $height / $sourceH);
            $copyW = max(1, (int) round($sourceW * $scale)); $copyH = max(1, (int) round($sourceH * $scale));
            imagecopyresampled($canvas, $source, (int) floor(($width - $copyW) / 2), (int) floor(($height - $copyH) / 2), 0, 0, $copyW, $copyH, $sourceW, $sourceH);
        }
        $thumbName = 'thumb-' . preg_replace('/^thumb-/', '', $name);
        $thumbPath = $this->uploadRoot() . DIRECTORY_SEPARATOR . $thumbName;
        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($canvas, $thumbPath, 88), 'image/png' => imagepng($canvas, $thumbPath, 6),
            'image/webp' => imagewebp($canvas, $thumbPath, 88), 'image/gif' => imagegif($canvas, $thumbPath), default => false,
        };
        imagedestroy($source); imagedestroy($canvas);
        if (!$saved) throw new HttpException(500, '缩略图保存失败');
        chmod($thumbPath, 0644);
        $this->audit->record('upload.thumbnail', 'upload', $thumbName, ['source' => $name], ['width' => $width, 'height' => $height, 'mode' => $mode]);
        return redirect('/admin/tools/uploads');
    }

    public function deleteUpload(string $name): Response
    {
        $this->guardCsrf();
        if ($name !== basename($name)) throw new HttpException(422, '附件名无效');
        $root = realpath($this->uploadRoot());
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new HttpException(404, '附件不存在');
        if (!unlink($path)) throw new HttpException(500, '附件删除失败');
        $this->audit->record('upload.delete', 'upload', $name);
        return redirect('/admin/tools/uploads');
    }

    public function duplicates(): Response
    {
        $categoryId = max(0, (int) $this->request->get('category_id', 0));
        $length = max(0, min(30, (int) $this->request->get('length', 0)));
        $keyExpression = $length > 0 ? 'LEFT(title,' . $length . ')' : 'title';
        $where = 'deleted_at IS NULL';
        $bindings = [];
        if ($categoryId > 0) { $where .= ' AND category_id = ?'; $bindings[] = $categoryId; }
        $groups = Db::query(
            'SELECT ' . $keyExpression . ' AS duplicate_key, COUNT(*) AS duplicate_count FROM ffx_media WHERE ' . $where
            . ' GROUP BY ' . $keyExpression . ' HAVING COUNT(*) > 1 ORDER BY duplicate_count DESC LIMIT 100',
            $bindings
        );
        foreach ($groups as &$group) {
            $items = Db::table('ffx_media')->whereNull('deleted_at');
            if ($categoryId > 0) $items->where('category_id', $categoryId);
            if ($length > 0) $items->whereRaw('LEFT(title,' . $length . ') = ?', [(string) $group['duplicate_key']]);
            else $items->where('title', (string) $group['duplicate_key']);
            $group['items'] = $items->field('id,title,release_year,status,updated_at')->order('id')->limit(50)->select()->toArray();
        }
        unset($group);
        return view('/admin/tools/duplicates', [
            'groups' => $groups, 'categoryId' => $categoryId, 'length' => $length,
            'categories' => Db::table('ffx_categories')->where('content_type', 'media')->where('status', 'published')->order('sort_order')->select()->toArray(),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function batchMaintenance(): Response
    {
        $sources = Db::table('ffx_play_sources')->field('source_key,MAX(display_name) AS display_name,COUNT(*) AS source_count')
            ->group('source_key')->order('source_count', 'desc')->select()->toArray();
        return view('/admin/tools/batch', [
            'sources' => $sources,
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function deletePlaybackSource(): Response
    {
        $this->guardCsrf();
        $key = mb_substr(trim((string) $this->request->post('source_key', '')), 0, 80);
        if ($key === '') throw new HttpException(422, '请选择需要删除的播放来源');
        $rows = Db::table('ffx_play_sources')->where('source_key', $key)->select()->toArray();
        $count = count($rows);
        if ($count > 0) Db::table('ffx_play_sources')->where('source_key', $key)->delete();
        $this->audit->record('tools.playback_delete', 'play_source', $key, ['count' => $count], null);
        return redirect('/admin/tools/batch?message=' . rawurlencode('已删除播放来源 ' . $key . '，共影响 ' . $count . ' 部视频'));
    }

    public function generateSlugs(): Response
    {
        $this->guardCsrf();
        $type = (string) $this->request->post('type', 'media');
        $maps = [
            'media' => ['ffx_media', 'title', 'vod'], 'article' => ['ffx_articles', 'title', 'news'],
            'topic' => ['ffx_topics', 'title', 'special'], 'person' => ['ffx_people', 'name', 'star'],
        ];
        if (!isset($maps[$type])) throw new HttpException(422, '内容类型无效');
        [$table, $titleField, $prefix] = $maps[$type];
        $rows = Db::table($table)->field('id,' . $titleField . ',slug')->order('id')->limit(5000)->select()->toArray();
        $updated = 0;
        foreach ($rows as $row) {
            $base = mb_strtolower((string) $row[$titleField]);
            $base = trim((string) preg_replace('/[^a-z0-9]+/u', '-', $base), '-');
            $slug = mb_substr(($base !== '' ? $base : $prefix) . '-' . (int) $row['id'], 0, 220);
            if ((string) $row['slug'] === $slug) continue;
            Db::table($table)->where('id', (int) $row['id'])->update(['slug' => $slug, 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $updated++;
        }
        $this->audit->record('tools.slug_generate', $type, 'batch', null, ['updated' => $updated]);
        return redirect('/admin/tools/batch?message=' . rawurlencode('链接别名生成完成，更新 ' . $updated . ' 条'));
    }

    public function generateTags(): Response
    {
        $this->guardCsrf();
        $scope = (string) $this->request->post('scope', 'media');
        if (!in_array($scope, ['media', 'article'], true)) throw new HttpException(422, '标签类型无效');
        $table = $scope === 'media' ? 'ffx_media' : 'ffx_articles';
        $relation = $scope === 'media' ? 'ffx_media_tags' : 'ffx_article_tags';
        $foreignKey = $scope === 'media' ? 'media_id' : 'article_id';
        $fields = $scope === 'media' ? 'id,title,area,language,release_year,metadata' : 'id,title,metadata';
        $rows = Db::table($table)->field($fields)->whereNull('deleted_at')->order('id')->limit(500)->select()->toArray();
        $created = 0;
        foreach ($rows as $row) {
            $metadata = $row['metadata'] ?? [];
            if (is_string($metadata)) $metadata = json_decode($metadata, true) ?: [];
            $terms = [];
            foreach (['keywords', 'tags', 'type'] as $field) {
                $value = is_array($metadata) ? ($metadata[$field] ?? '') : '';
                if (is_array($value)) $value = implode(',', $value);
                $terms = array_merge($terms, preg_split('/[\s,\/、|]+/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
            if ($scope === 'media') {
                foreach (['area', 'language', 'release_year'] as $field) if (!empty($row[$field])) $terms[] = (string) $row[$field];
            }
            $terms = array_slice(array_values(array_unique(array_filter(array_map(static fn ($v) => mb_substr(trim((string) $v), 0, 100), $terms)))), 0, 12);
            foreach ($terms as $term) {
                $slugBase = trim((string) preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($term)), '-');
                $slug = $slugBase !== '' ? $slugBase : 'tag-' . substr(sha1($term), 0, 16);
                $tag = Db::table('ffx_tags')->where('scope', $scope)->where('slug', $slug)->find();
                if ($tag === null) {
                    $tagId = (int) Db::table('ffx_tags')->insertGetId(['scope' => $scope, 'name' => $term, 'slug' => $slug, 'created_at' => gmdate('Y-m-d H:i:s')]);
                } else $tagId = (int) $tag['id'];
                if (Db::table($relation)->where($foreignKey, (int) $row['id'])->where('tag_id', $tagId)->count() === 0) {
                    Db::table($relation)->insert([$foreignKey => (int) $row['id'], 'tag_id' => $tagId]);
                    $created++;
                }
            }
        }
        $this->audit->record('tools.tag_generate', $scope, 'batch', null, ['relations' => $created]);
        return redirect('/admin/tools/batch?message=' . rawurlencode('标签生成完成，新增 ' . $created . ' 个内容标签关联'));
    }

    public function records(): Response
    {
        return view('/admin/tools/records', [
            'history' => Db::table('ffx_watch_history')->alias('h')->leftJoin(['ffx_users' => 'u'], 'u.id=h.user_id')->join(['ffx_media' => 'm'], 'm.id=h.media_id')->field('h.*,u.username,m.title')->order('h.watched_at', 'desc')->limit(200)->select()->toArray(),
            'favorites' => Db::table('ffx_favorites')->alias('f')->join(['ffx_users' => 'u'], 'u.id=f.user_id')->join(['ffx_media' => 'm'], 'm.id=f.media_id')->field('f.*,u.username,m.title')->order('f.created_at', 'desc')->limit(200)->select()->toArray(),
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function deleteHistory(int $id): Response
    {
        $this->guardCsrf();
        $before = Db::table('ffx_watch_history')->where('id', $id)->find();
        if ($before === null) throw new HttpException(404, '播放记录不存在');
        Db::table('ffx_watch_history')->where('id', $id)->delete();
        $this->audit->record('records.history.delete', 'watch_history', (string) $id, $before, null);
        return redirect('/admin/tools/records?message=' . rawurlencode('播放记录已删除'));
    }

    public function deleteFavorite(): Response
    {
        $this->guardCsrf();
        $userId = max(0, (int) $this->request->post('user_id', 0));
        $mediaId = max(0, (int) $this->request->post('media_id', 0));
        $before = Db::table('ffx_favorites')->where('user_id', $userId)->where('media_id', $mediaId)->find();
        if ($before === null) throw new HttpException(404, '收藏记录不存在');
        Db::table('ffx_favorites')->where('user_id', $userId)->where('media_id', $mediaId)->delete();
        $this->audit->record('records.favorite.delete', 'favorite', $userId . ':' . $mediaId, $before, null);
        return redirect('/admin/tools/records?message=' . rawurlencode('收藏记录已删除'));
    }

    public function clearRecords(): Response
    {
        $this->guardCsrf();
        if ((string) $this->request->post('confirm') !== 'CLEAR') throw new HttpException(422, '缺少清空确认');
        $type = (string) $this->request->post('type', '');
        $map = ['history' => ['ffx_watch_history', '播放记录'], 'favorites' => ['ffx_favorites', '用户收藏']];
        if (!isset($map[$type])) throw new HttpException(422, '记录类型无效');
        [$table, $label] = $map[$type];
        $count = (int) Db::table($table)->count();
        if ($count > 0) Db::table($table)->delete(true);
        $this->audit->record('records.clear', $type, 'all', ['count' => $count], null);
        return redirect('/admin/tools/records?message=' . rawurlencode($label . '已清空，共删除 ' . $count . ' 条'));
    }

    public function replace(): Response
    {
        return view('/admin/tools/replace', [
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 240),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function runReplace(): Response
    {
        $this->guardCsrf();
        $scope = (string) $this->request->post('scope', 'media');
        $field = (string) $this->request->post('field', 'title');
        $search = (string) $this->request->post('search', '');
        $replacement = (string) $this->request->post('replacement', '');
        $preview = (string) $this->request->post('preview', '') === '1';
        $maps = [
            'media' => ['ffx_media', ['title', 'subtitle', 'original_title', 'summary', 'content', 'area', 'language']],
            'article' => ['ffx_articles', ['title', 'summary', 'content', 'author', 'source_url']],
            'person' => ['ffx_people', ['name', 'aliases', 'summary', 'biography', 'nationality', 'profession']],
            'topic' => ['ffx_topics', ['title', 'summary', 'content']],
        ];
        if (!isset($maps[$scope])) throw new HttpException(422, '内容类型无效');
        [$table, $fields] = $maps[$scope];
        if (!in_array($field, $fields, true)) throw new HttpException(422, '替换字段不在白名单中');
        if ($search === '' || mb_strlen($search) > 500 || mb_strlen($replacement) > 5000) throw new HttpException(422, '查找内容不能为空，且内容长度必须合理');

        $rows = Db::table($table)->field('id,' . $field)->whereNull('deleted_at')
            ->whereLike($field, '%' . addcslashes($search, '%_\\') . '%')->order('id')->limit(5001)->select()->toArray();
        if (count($rows) > 5000) throw new HttpException(422, '匹配超过 5000 条，请缩小查找范围后分批处理');
        $changed = 0;
        if (!$preview) {
            Db::transaction(function () use ($rows, $table, $field, $search, $replacement, &$changed): void {
                foreach ($rows as $row) {
                    $old = (string) ($row[$field] ?? '');
                    $new = str_replace($search, $replacement, $old);
                    if ($new === $old) continue;
                    Db::table($table)->where('id', (int) $row['id'])->update([$field => $new, 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    $changed++;
                }
            });
            $this->audit->record('tools.data_replace', $scope, $field, null, [
                'matched' => count($rows), 'changed' => $changed,
                'search_sha256' => hash('sha256', $search), 'replacement_sha256' => hash('sha256', $replacement),
            ]);
        }
        $message = $preview ? '预览完成：匹配 ' . count($rows) . ' 条，不会修改数据' : '替换完成：匹配 ' . count($rows) . ' 条，实际更新 ' . $changed . ' 条';
        return redirect('/admin/tools/replace?message=' . rawurlencode($message));
    }

    public function staticPages(): Response
    {
        $directory = public_path() . 'generated';
        $files = [];
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[] = ['name' => $relative, 'size' => $file->getSize(), 'time' => $file->getMTime(), 'url' => '/generated/' . implode('/', array_map('rawurlencode', explode('/', $relative)))];
            }
        }
        usort($files, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
        return view('/admin/tools/static', [
            'files' => $files, 'message' => mb_substr((string) $this->request->get('message', ''), 0, 240),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function generateStatic(): Response
    {
        $this->guardCsrf();
        $directory = public_path() . 'generated';
        if (!is_dir($directory)) mkdir($directory, 0755, true);
        chmod($directory, 0755);
        $base = rtrim((string) $this->request->domain(), '/');
        $scopes = array_values(array_intersect((array) $this->request->post('scopes', ['feeds']), ['feeds', 'home', 'categories', 'media', 'articles', 'topics']));
        if ($scopes === []) throw new HttpException(422, '请选择至少一种生成内容');
        $limit = max(1, min(5000, (int) $this->request->post('limit', 500)));
        $rows = Db::table('ffx_media')->where('status', 'published')->whereNull('deleted_at')->order('id', 'desc')->limit($this->settings->int('admin.content.sitemap_limit', 1000, 1, 50000))->select()->toArray();
        $items = '';
        $rss = '';
        foreach ($rows as $row) {
            $url = $base . ff_url('vod', (int) $row['id']);
            $items .= '<url><loc>' . htmlspecialchars($url, ENT_XML1) . '</loc><lastmod>' . gmdate('c', strtotime((string) $row['updated_at'])) . '</lastmod></url>';
            if (substr_count($rss, '<item>') < 100) $rss .= '<item><title>' . htmlspecialchars((string) $row['title'], ENT_XML1) . '</title><link>' . htmlspecialchars($url, ENT_XML1) . '</link></item>';
        }
        $generated = 0;
        $failed = 0;
        $failureMessages = [];
        if (in_array('feeds', $scopes, true)) {
            $sitemapPath = $directory . '/sitemap.xml';
            $rssPath = $directory . '/rss.xml';
            file_put_contents($sitemapPath, '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $items . '</urlset>', LOCK_EX);
            file_put_contents($rssPath, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . htmlspecialchars($this->settings->string('admin.base.site_name', (string) config('feifei.site_name')), ENT_XML1) . '</title><link>' . htmlspecialchars($base, ENT_XML1) . '</link>' . $rss . '</channel></rss>', LOCK_EX);
            chmod($sitemapPath, 0644);
            chmod($rssPath, 0644);
            $generated += 2;
        }

        $targets = [];
        if (in_array('home', $scopes, true)) $targets[] = ['url' => '/', 'file' => 'index.html', 'kind' => 'home', 'id' => 0];
        $definitions = [
            'categories' => ['ffx_categories', 'list', 'category'],
            'media' => ['ffx_media', 'vod', 'vod'],
            'articles' => ['ffx_articles', 'news', 'news'],
            'topics' => ['ffx_topics', 'special', 'special'],
        ];
        foreach ($definitions as $scope => [$table, $urlType, $folder]) {
            if (!in_array($scope, $scopes, true)) continue;
            $query = Db::table($table)->field('id')->where('status', 'published')->whereNull('deleted_at')->order('id', 'desc')->limit($limit)->select()->toArray();
            foreach ($query as $row) $targets[] = ['url' => ff_url($urlType, (int) $row['id']), 'file' => $folder . '/' . (int) $row['id'] . '.html', 'kind' => $scope, 'id' => (int) $row['id']];
        }
        if ($targets !== []) {
            foreach ($targets as $target) {
                try {
                    $body = $this->renderStaticTarget((string) $target['kind'], (int) $target['id']);
                    if ($body === '') {
                        $failed++;
                        $failureMessages[] = $target['url'] . ' 渲染结果为空';
                        continue;
                    }
                    $path = $directory . '/' . $target['file'];
                    $parent = dirname($path);
                    if (!is_dir($parent)) mkdir($parent, 0755, true);
                    if (file_put_contents($path, $body, LOCK_EX) === false) { $failed++; continue; }
                    chmod($path, 0644);
                    $generated++;
                } catch (Throwable $exception) {
                    $failed++;
                    $failureMessages[] = $target['url'] . ' ' . mb_substr($exception->getMessage(), 0, 120);
                }
            }
        }
        $this->audit->record('static.generate', 'system', 'static', null, ['scopes' => $scopes, 'generated' => $generated, 'failed' => $failed, 'failures' => array_slice($failureMessages, 0, 20)]);
        $message = '生成完成：成功 ' . $generated . ' 个，失败 ' . $failed . ' 个';
        if ($failureMessages !== []) $message .= '；' . implode('；', array_slice($failureMessages, 0, 2));
        return redirect('/admin/tools/static?message=' . rawurlencode($message));
    }

    private function renderStaticTarget(string $kind, int $id): string
    {
        $response = match ($kind) {
            'home' => app()->make(\app\controller\Index::class)->index(),
            'categories' => app()->make(\app\controller\Category::class)->show($id),
            'media' => app()->make(\app\controller\Vod::class)->detail($id),
            'articles' => app()->make(\app\controller\News::class)->detail($id),
            'topics' => app()->make(\app\controller\Special::class)->detail($id),
            default => throw new HttpException(422, '不支持的静态生成类型'),
        };
        return (string) $response->getContent();
    }

    private function versionDetails(): array
    {
        $schema = 0;
        $mysql = '未知';
        try {
            $schema = (int) Db::table('ffx_schema_versions')->max('version');
            $mysql = (string) (Db::query('SELECT VERSION() AS version')[0]['version'] ?? '未知');
        } catch (Throwable) {
        }
        $thinkphp = class_exists(\Composer\InstalledVersions::class)
            ? (string) (\Composer\InstalledVersions::getPrettyVersion('topthink/framework') ?: '未知')
            : '未知';
        return [
            'app' => (string) config('feifei.version'),
            'thinkphp' => $thinkphp,
            'php' => PHP_VERSION,
            'mysql' => $mysql,
            'schema' => $schema,
            'composer' => is_file(root_path() . 'composer.lock') && is_file(root_path() . 'vendor/autoload.php'),
        ];
    }

    private function templatePath(string $relative): string
    {
        $relative = $this->normalizeTemplateRelative($relative);
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->templateEditableExtensions(), true)) throw new HttpException(422, '该文件类型不允许在线编辑');
        $root = $this->templateRoot();
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new HttpException(404, '模板文件不存在');
        return $path;
    }

    private function newTemplatePath(string $relative): string
    {
        $relative = $this->normalizeTemplateRelative($relative);
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->templateEditableExtensions(), true)) throw new HttpException(422, '模板后缀不支持');
        $root = $this->templateRoot();
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function templateRoot(): string
    {
        $root = realpath(root_path() . 'view');
        if ($root === false) throw new HttpException(500, '模板目录不存在');
        return $root;
    }

    private function templateDirectoryPath(string $relative): string
    {
        $root = $this->templateRoot();
        if ($relative === '') return $root;
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_dir($path)) throw new HttpException(404, '模板目录不存在');
        return $path;
    }

    private function normalizeTemplateRelative(string $relative, bool $allowEmpty = false): string
    {
        $relative = trim(str_replace('\\', '/', rawurldecode($relative)), '/');
        if ($relative === '') {
            if ($allowEmpty) return '';
            throw new HttpException(422, '模板路径无效');
        }
        if (str_contains($relative, "\0") || !preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#', $relative)) throw new HttpException(422, '模板路径无效');
        if ($relative === 'admin' || str_starts_with($relative, 'admin/')) throw new HttpException(403, '后台管理模板不允许在此编辑');
        return $relative;
    }

    /** @return list<string> */
    private function templateEditableExtensions(): array
    {
        return ['html', 'htm', 'shtml', 'shtm', 'xml', 'js', 'css', 'tpl', 'txt'];
    }

    private function templateDescription(string $relative): string
    {
        $name = strtolower(basename($relative));
        $directory = strtolower(dirname($relative));
        return match (true) {
            $name === 'footer.html', $name === 'footer.tpl' => '底部公用模板',
            $name === 'header.html', $name === 'header.tpl' => '顶部公用模板',
            $name === 'index.html' && str_ends_with($directory, 'index') => '网站首页模板',
            $name === 'play.html', $name === 'vod_play.tpl' => '播放页模板',
            $name === 'detail.html' && str_ends_with($directory, 'vod'), $name === 'vod_detail.tpl' => '视频内容模板',
            $name === 'detail.html' && str_ends_with($directory, 'news'), $name === 'news_detail.tpl' => '文章内容模板',
            $name === 'detail.html' && str_ends_with($directory, 'special'), $name === 'special_detail.tpl' => '专题内容模板',
            $name === 'system.css' => '模板主题样式表',
            str_ends_with($name, '.css') => 'CSS样式表',
            str_ends_with($name, '.js') => 'Javascript文件',
            str_ends_with($name, '.xml') => 'XML文件',
            str_starts_with($name, 'my_') => '自定义模板',
            str_starts_with($name, 'map_') => '地图页模板',
            str_starts_with($name, 'block_') => '区块标签',
            default => '模板文件',
        };
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
    }

    private function uploadRoot(): string
    {
        $relative = trim((string) preg_replace('/[^A-Za-z0-9_\/-]+/', '', $this->settings->string('admin.files.upload_path', 'uploads')), '/');
        return public_path() . ($relative !== '' ? $relative : 'uploads');
    }

    private function uploadUrl(string $name): string
    {
        $default = '/' . trim($this->settings->string('admin.files.upload_path', 'uploads'), '/');
        $base = trim($this->settings->string('admin.files.base_url'));
        return rtrim($base !== '' ? $base : $default, '/') . '/' . rawurlencode($name);
    }

    private function existingUploadPath(string $name): string
    {
        if ($name !== basename($name)) throw new HttpException(422, '附件名无效');
        $root = realpath($this->uploadRoot());
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) throw new HttpException(404, '附件不存在');
        return $path;
    }

    /** @return array{files:int,bytes:int,size:string} */
    private function directoryStats(string $directory): array
    {
        $files = 0;
        $bytes = 0;
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) if ($file->isFile() && !$file->isLink()) { $files++; $bytes += $file->getSize(); }
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return ['files' => $files, 'bytes' => $bytes, 'size' => number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit]];
    }

    private function clearDirectory(string $directory): int
    {
        if (!is_dir($directory)) return 0;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) { if (@unlink($item->getPathname())) $count++; }
            elseif ($item->isDir()) @rmdir($item->getPathname());
        }
        return $count;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
