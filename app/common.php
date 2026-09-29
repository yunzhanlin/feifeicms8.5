<?php
// 应用公共文件

if (!function_exists('ff_url')) {
    /**
     * FeiFeiCMS template URL contract. Templates call logical resources and
     * never need to know the current ThinkPHP controller or rewrite rule.
     */
    function ff_url(string $name, mixed $id = 0, bool $suffix = true): string
    {
        $params = is_array($id) ? $id : ['id' => $id];
        $value = rawurlencode((string) ($params['id'] ?? 0));
        $query = static function (array $values, array $skip = ['id']): string {
            foreach ($skip as $key) unset($values[$key]);
            $values = array_filter($values, static fn ($item): bool => $item !== '' && $item !== null);
            return $values === [] ? '' : '?' . http_build_query($values);
        };
        $normalized = strtolower($name);
        $fallback = match ($normalized) {
            'home', 'index' => '/',
            'type', 'list', 'list/read', 'list/select' => '/list/' . $value . $query($params),
            'vod', 'vod/read' => '/vod/read/id/' . $value,
            'vod/forum', 'comments', 'forum' => '/vod/' . $value . '/comments',
            'news', 'news/read' => '/news/read/id/' . $value,
            'special', 'special/read' => '/special/read/id/' . $value,
            'person', 'star', 'role', 'star/read', 'role/read' => '/star/' . $value,
            'guestbook' => '/guestbook',
            'search', 'vod/search' => '/vod/search' . $query($params, []),
            'latest' => '/latest',
            'hot' => '/hot',
            'map' => '/map',
            'rss' => '/rss',
            'payment' => '/payment',
            'vip' => '/vip',
            'user/login' => '/user/login',
            'user/register' => '/user/register',
            'user/center' => '/user/center',
            default => '/' . trim(strtolower($name), '/') . ($value !== '0' && $value !== '' ? '/' . $value : '') . $query($params),
        };
        if (in_array($normalized, ['type', 'list', 'list/read', 'list/select', 'vod', 'vod/read', 'vod/forum', 'comments', 'forum', 'news', 'news/read', 'special', 'special/read', 'person', 'star', 'role', 'star/read', 'role/read', 'scenario', 'scenario/read', 'guestbook', 'guestbook/read', 'forum/read', 'user', 'user/index', 'user/center', 'vod/juqing', 'vod/taici', 'vod/zixun', 'vod/yanyuan', 'vod/pingfen', 'vod/kandian', 'vod/shoubo', 'vod/jieju', 'vod/rss', 'vod/yugao', 'vod/xiazai'], true)) {
            try {
                return app()->make(app\service\LegacyUrlGenerator::class)->generate($normalized, $params, $suffix, $fallback);
            } catch (\Throwable) {
                // Installer and database maintenance tasks can run before settings are available.
            }
        }
        if ($suffix && in_array($normalized, ['vod', 'vod/read', 'news', 'news/read'], true)) return $fallback . '.html';
        return $fallback;
    }
}

if (!function_exists('ff_play_url')) {
    /** Runtime positions are zero-based; public source and episode numbers start at one. */
    function ff_play_url(int|string $vodId, int|string $sourceIndex = 0, int|string $episodeIndex = 0): string
    {
        try {
            return app()->make(app\service\LegacyUrlGenerator::class)->play($vodId, $sourceIndex, $episodeIndex);
        } catch (\Throwable) {
            return '/vod/play/id/' . rawurlencode((string) $vodId)
                . '/sid/' . app\service\PlaybackPosition::publicSourceNumber($sourceIndex)
                . '/pid/' . app\service\PlaybackPosition::publicEpisodeNumber($episodeIndex) . '.html';
        }
    }
}

if (!function_exists('ff_theme_view')) {
    /** Resolve a frontend view through the selected desktop/mobile theme. */
    function ff_theme_view(string $template): string
    {
        try {
            return app()->make(app\service\ThemeRegistry::class)->template($template);
        } catch (\Throwable) {
            return 'mxone/' . ltrim($template, '/');
        }
    }
}

if (!function_exists('ff_url_read_vod')) {
    function ff_url_read_vod(mixed $listId, mixed $listDir, mixed $vodId, mixed $ename = '', mixed $jump = '', bool $suffix = true): string
    {
        return trim((string) $jump) !== '' ? (string) $jump : ff_url('vod', ['id' => (int) $vodId, 'list_id' => (int) $listId, 'list_dir' => (string) $listDir, 'ename' => (string) $ename], $suffix);
    }
}

if (!function_exists('ff_url_read_news')) {
    function ff_url_read_news(mixed $listId, mixed $listDir, mixed $newsId, mixed $ename = '', mixed $jump = '', mixed $page = 1, bool $suffix = true): string
    {
        return trim((string) $jump) !== '' ? (string) $jump : ff_url('news', ['id' => (int) $newsId, 'list_id' => (int) $listId, 'list_dir' => (string) $listDir, 'ename' => (string) $ename, 'page' => max(1, (int) $page)], $suffix);
    }
}

if (!function_exists('ff_url_read_special')) {
    function ff_url_read_special(mixed $listId, mixed $listDir, mixed $id, mixed $ename = '', bool $suffix = true): string { return ff_url('special', ['id' => (int) $id, 'ename' => (string) $ename], $suffix); }
}

if (!function_exists('ff_url_read_star')) {
    function ff_url_read_star(mixed $listId, mixed $listDir, mixed $id, mixed $ename = '', bool $suffix = true): string { return ff_url('star', ['id' => (int) $id, 'ename' => (string) $ename], $suffix); }
}

if (!function_exists('ff_url_read_role')) {
    function ff_url_read_role(mixed $listId, mixed $listDir, mixed $id, mixed $ename = '', bool $suffix = true): string { return ff_url('role', ['id' => (int) $id, 'ename' => (string) $ename], $suffix); }
}

if (!function_exists('ff_url_play')) {
    function ff_url_play(mixed $listId, mixed $listDir, mixed $vodId, mixed $ename = '', mixed $sid = 0, mixed $pid = 0, bool $suffix = true): string
    {
        try {
            return app()->make(app\service\LegacyUrlGenerator::class)->play((int) $vodId, (int) $sid, (int) $pid, ['list_id' => (int) $listId, 'list_dir' => (string) $listDir, 'ename' => (string) $ename]);
        } catch (\Throwable) {
            return ff_play_url((int) $vodId, (int) $sid, (int) $pid);
        }
    }
}

// FeiFeiCMS 4.1/4.3 template compatibility aliases.
if (!function_exists('ff_url_vod_read')) {
    function ff_url_vod_read(mixed $listId, mixed $listDir, mixed $vodId, mixed $ename = '', mixed $jump = '', bool $suffix = true): string
    {
        return ff_url_read_vod($listId, $listDir, $vodId, $ename, $jump, $suffix);
    }
}

if (!function_exists('ff_url_vod_play')) {
    function ff_url_vod_play(mixed $listId, mixed $listDir, mixed $vodId, mixed $ename = '', mixed $sid = 0, mixed $pid = 0, bool $suffix = true): string
    {
        return ff_url_play($listId, $listDir, $vodId, $ename, $sid, $pid, $suffix);
    }
}

if (!function_exists('ff_list_ids')) {
    function ff_list_ids(mixed $categoryId): string
    {
        $rows = think\facade\Db::table('ffx_categories')->whereNull('deleted_at')->select()->toArray();
        return implode(',', (new app\service\FrontendData())->categoryIds($rows, (int) $categoryId));
    }
}

if (!function_exists('ff_legacy_template')) {
    function ff_legacy_template(): app\service\LegacyTemplate { return app()->make(app\service\LegacyTemplate::class); }
}

if (!function_exists('ff_mysql_list')) { function ff_mysql_list(string $tag = ''): array { return ff_legacy_template()->categories($tag); } }
if (!function_exists('ff_mysql_vod')) { function ff_mysql_vod(string $tag = ''): array { return ff_legacy_template()->vod($tag); } }
if (!function_exists('ff_mysql_news')) { function ff_mysql_news(string $tag = ''): array { return ff_legacy_template()->news($tag); } }
if (!function_exists('ff_mysql_special')) { function ff_mysql_special(string $tag = ''): array { return ff_legacy_template()->specials($tag); } }
if (!function_exists('ff_mysql_star')) { function ff_mysql_star(string $tag = ''): array { return ff_legacy_template()->people($tag, 'person'); } }
if (!function_exists('ff_mysql_role')) { function ff_mysql_role(string $tag = ''): array { return ff_legacy_template()->people($tag, 'role'); } }
if (!function_exists('ff_mysql_tags')) { function ff_mysql_tags(string $tag = ''): array { return ff_legacy_template()->tags($tag); } }
if (!function_exists('ff_mysql_slide')) { function ff_mysql_slide(string $tag = ''): array { return ff_legacy_template()->slides($tag); } }
if (!function_exists('ff_mysql_nav')) { function ff_mysql_nav(string $tag = ''): array { return ff_legacy_template()->navigation($tag); } }
