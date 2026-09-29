<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Generates links from the same logical rule vocabulary used by FeiFeiCMS 4.3/7.4. */
final class LegacyUrlGenerator
{
    /** @var array<string,array<string,mixed>|null> */
    private array $recordCache = [];

    public function __construct(private readonly SiteSettings $settings, private readonly LegacyRewriteRules $rules) {}

    /** @param array<string,mixed> $params */
    public function generate(string $name, array $params, bool $withSuffix, string $fallback): string
    {
        $name = strtolower(trim($name, '/'));
        $suffix = $withSuffix ? $this->suffix() : '';
        if (!$this->settings->bool('admin.rewrite.url_rewrite', true)) {
            $actions = $this->actions($name, $params, false);
            return $actions === [] ? $fallback : '/index.php?s=/' . $this->thinkPath(end($actions) ?: '') . $suffix;
        }

        if ($this->settings->bool('admin.rewrite.url_router_on', true)) {
            $definition = $this->settings->string('admin.rewrite.rewrite_route', '');
            if (trim($definition) !== '') {
                try {
                    $match = $this->rules->matchRewrite($this->actions($name, $params, true), $definition);
                    if ($match !== null) return '/' . ltrim($match['path'], '/') . $suffix . $this->remainingQuery($params, $match['action']);
                } catch (\Throwable) {
                    // Invalid data entered outside the admin must not break every frontend link.
                }
            }
        }
        if (!$withSuffix || $suffix === '' || str_contains($fallback, '?') || preg_match('/\.[a-z0-9]+$/i', $fallback)) return $fallback;
        return rtrim($fallback, '/') . $suffix;
    }

    /** Runtime indices are zero based; the public contract starts at one. */
    public function play(int|string $vodId, int|string $sourceIndex, int|string $episodeIndex, array $context = []): string
    {
        $params = $context + [
            'id' => max(0, (int) $vodId),
            'sid' => PlaybackPosition::publicSourceNumber($sourceIndex),
            'pid' => PlaybackPosition::publicEpisodeNumber($episodeIndex),
        ];
        return $this->generate('vod/play', $params, true, '/vod/play/id/' . $params['id'] . '/sid/' . $params['sid'] . '/pid/' . $params['pid']);
    }

    private function suffix(): string
    {
        $suffix = $this->settings->string('admin.rewrite.url_html_suffix', '.html');
        return in_array($suffix, ['', '.html', '.htm', '.shtml', '.shtm'], true) ? $suffix : '.html';
    }

    /** @param array<string,mixed> $params @return list<string> */
    private function actions(string $name, array $params, bool $enrich): array
    {
        $id = max(0, (int) ($params['id'] ?? 0));
        $page = max(1, (int) ($params['page'] ?? $params['p'] ?? 1));
        $sid = max(1, (int) ($params['sid'] ?? 1));
        $pid = max(1, (int) ($params['pid'] ?? 1));
        $dir = $this->clean((string) ($params['dir'] ?? $params['list_dir'] ?? ''));
        $slug = $this->clean((string) ($params['ename'] ?? $params['slug'] ?? ''));
        $categoryId = max(0, (int) ($params['cid'] ?? $params['list_id'] ?? 0));

        if ($enrich && in_array($name, ['type', 'list', 'list/read', 'list/select'], true)) {
            $category = $this->record('ffx_categories', $id);
            $dir = $dir ?: $this->clean((string) ($category['slug'] ?? ''));
        } elseif ($enrich && in_array($name, ['vod', 'vod/read', 'vod/play', 'play', 'vod/forum', 'comments', 'forum'], true)) {
            $media = $this->record('ffx_media', $id);
            $slug = $slug ?: $this->clean((string) ($media['slug'] ?? ''));
            $categoryId = $categoryId ?: (int) ($media['category_id'] ?? 0);
            $category = $this->record('ffx_categories', $categoryId);
            $dir = $dir ?: $this->clean((string) ($category['slug'] ?? ''));
        } elseif ($enrich && in_array($name, ['news', 'news/read'], true)) {
            $article = $this->record('ffx_articles', $id);
            $slug = $slug ?: $this->clean((string) ($article['slug'] ?? ''));
        } elseif ($enrich && in_array($name, ['special', 'special/read'], true)) {
            $topic = $this->record('ffx_topics', $id);
            $slug = $slug ?: $this->clean((string) ($topic['slug'] ?? ''));
        } elseif ($enrich && in_array($name, ['person', 'star', 'star/read', 'role', 'role/read'], true)) {
            $person = $this->record('ffx_people', $id);
            $slug = $slug ?: $this->clean((string) ($person['slug'] ?? ''));
        }

        return match ($name) {
            'type', 'list', 'list/read', 'list/select' => array_values(array_filter([
                $dir !== '' ? 'list-ename-id-' . $dir . ($page > 1 ? '-p-' . $page : '') : null,
                'list-read-id-' . $id . ($page > 1 ? '-p-' . $page : ''),
                'list-read-id-' . $id,
            ])),
            'vod', 'vod/read' => array_values(array_filter([
                $dir !== '' ? 'vod-read-dir-' . $dir . '-id-' . ($slug !== '' ? $slug : (string) $id) : null,
                $slug !== '' ? 'vod-ename-id-' . $slug : null,
                'vod-read-id-' . $id,
            ])),
            'vod/play', 'play' => array_values(array_filter([
                $dir !== '' ? 'vod-play-dir-' . $dir . '-id-' . $id . '-sid-' . $sid . '-pid-' . $pid : null,
                $slug !== '' ? 'vod-eplay-id-' . $slug . '-sid-' . $sid . '-pid-' . $pid : null,
                'vod-play-id-' . $id . '-sid-' . $sid . '-pid-' . $pid,
            ])),
            'news', 'news/read' => array_values(array_filter([
                $slug !== '' ? 'news-ename-id-' . $slug . ($page > 1 ? '-p-' . $page : '') : null,
                'news-read-id-' . $id . ($page > 1 ? '-p-' . $page : ''),
                'news-read-id-' . $id,
            ])),
            'special', 'special/read' => ['special-read-id-' . $id],
            'person', 'star', 'star/read' => ['star-read-id-' . $id],
            'role', 'role/read' => ['role-read-id-' . $id],
            'scenario', 'scenario/read' => ['scenario-read-id-' . $id . ($pid > 1 ? '-pid-' . $pid : '')],
            'vod/forum', 'comments', 'forum' => ['vod-forum-id-' . $id . ($page > 1 ? '-p-' . $page : '')],
            'guestbook', 'guestbook/read' => ['guestbook-read-id-' . max(1, $id)],
            'forum/read' => ['forum-read-id-' . max(1, $id)],
            'user', 'user/index', 'user/center' => ['user-index-id-' . max(1, $id)],
            'vod/juqing', 'vod/taici', 'vod/zixun', 'vod/yanyuan', 'vod/pingfen', 'vod/kandian', 'vod/shoubo', 'vod/jieju', 'vod/rss', 'vod/yugao', 'vod/xiazai' => ['vod-' . substr($name, 4) . '-id-' . $id],
            default => [],
        };
    }

    /** @return array<string,mixed>|null */
    private function record(string $table, int $id): ?array
    {
        if ($id < 1) return null;
        $key = $table . ':' . $id;
        if (!array_key_exists($key, $this->recordCache)) {
            try {
                $this->recordCache[$key] = Db::table($table)->where('id', $id)->whereNull('deleted_at')->find();
            } catch (\Throwable) {
                $this->recordCache[$key] = null;
            }
        }
        return $this->recordCache[$key];
    }

    private function clean(string $value): string
    {
        return preg_match('/^[A-Za-z0-9]+$/', $value) ? $value : '';
    }

    /** @param array<string,mixed> $params */
    private function remainingQuery(array $params, string $action): string
    {
        foreach (['id', 'sid', 'pid', 'dir', 'list_dir', 'ename', 'slug', 'cid', 'list_id'] as $key) unset($params[$key]);
        if (str_contains($action, '-p-') || preg_match('/^(?:list|news|vod-forum)-/', $action)) unset($params['page'], $params['p']);
        $params = array_filter($params, static fn (mixed $value): bool => $value !== '' && $value !== null);
        return $params === [] ? '' : '?' . http_build_query($params);
    }

    private function thinkPath(string $action): string
    {
        $segments = explode('-', $action);
        if (count($segments) < 2) return $action;
        $path = ucfirst((string) array_shift($segments)) . '/' . array_shift($segments);
        while (count($segments) >= 2) $path .= '/' . array_shift($segments) . '/' . array_shift($segments);
        return $path;
    }
}
