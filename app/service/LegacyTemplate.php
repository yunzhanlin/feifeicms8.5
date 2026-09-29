<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Read-only FeiFeiCMS template data adapter.
 *
 * Old FeiFei themes express queries as `key:value;key:value`.  This class
 * preserves that public template contract but translates it to the normalized
 * ffx_* schema.  Only named filters and whitelisted sort keys are accepted.
 */
final class LegacyTemplate
{
    public function __construct(private readonly FrontendData $frontend) {}

    /** @return array<int, array<string, mixed>> */
    public function categories(string $tag = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_categories')->whereNull('deleted_at');
        if (($p['status'] ?? 'published') !== 'all') $query->where('status', 'published');
        if (!empty($p['sid'])) $query->where('content_type', $this->contentType((string) $p['sid']));
        if (!empty($p['pid'])) $query->where('parent_id', (int) $p['pid']);
        if (!empty($p['ids'])) $query->whereIn('id', $this->ids((string) $p['ids']));
        $rows = $query->order('parent_id')->order('sort_order')->order('id')->limit($this->limit($p, 50))->select()->toArray();
        return array_map(fn (array $row): array => $this->frontend->type($row) + [
            'list_pid' => (int) ($row['parent_id'] ?? 0), 'list_oid' => (int) ($row['sort_order'] ?? 0),
            'list_dir' => (string) ($row['slug'] ?? ''), 'list_link' => ff_url('type', (int) $row['id']),
            'list_extend' => is_array($row['filter_options'] ?? null) ? $row['filter_options'] : (json_decode((string) ($row['filter_options'] ?? ''), true) ?: []),
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function vod(string $tag = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_media')->whereNull('deleted_at');
        if (($p['status'] ?? 'published') !== 'all') $query->where('status', 'published');
        $cid = $p['cid'] ?? $p['ids'] ?? '';
        if ($cid !== '') $query->whereIn('category_id', $this->ids((string) $cid));
        if (!empty($p['id'])) $query->whereIn('id', $this->ids((string) $p['id']));
        if (!empty($p['year'])) $query->where('release_year', (int) $p['year']);
        foreach (['area' => 'area', 'language' => 'language'] as $key => $column) {
            if (!empty($p[$key])) $query->whereLike($column, '%' . $this->like((string) $p[$key]) . '%');
        }
        $wd = trim((string) ($p['wd'] ?? $p['name'] ?? ''));
        if ($wd !== '') $query->whereLike('title', '%' . $this->like($wd) . '%');
        foreach (['actor', 'director', 'type'] as $metaKey) {
            if (!empty($p[$metaKey])) $query->whereLike('metadata', '%' . $this->like((string) $p[$metaKey]) . '%');
        }
        [$order, $sort] = $this->order($p, [
            'vod_id' => 'id', 'vod_addtime' => 'updated_at', 'vod_hits' => 'view_count', 'vod_hits_month' => 'view_count',
            'vod_gold' => 'rating', 'vod_stars' => 'weight', 'vod_up' => 'like_count', 'vod_year' => 'release_year',
        ], 'updated_at');
        $rows = $query->order($order, $sort)->order('id', 'desc')->limit($this->limit($p, 20))->select()->toArray();
        return array_map(function (array $row): array {
            $vod = $this->frontend->vod($row);
            return $vod + [
                'vod_link' => ff_url('vod', (int) $row['id']),
                'vod_play' => ff_play_url((int) $row['id'], 0, 0),
                'list_id' => (int) ($row['category_id'] ?? 0),
                'list_name' => (string) ($vod['vod_type'] ?? ''),
            ];
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function news(string $tag = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_articles')->whereNull('deleted_at')->where('status', 'published');
        if (!empty($p['cid'])) $query->whereIn('category_id', $this->ids((string) $p['cid']));
        if (!empty($p['id'])) $query->whereIn('id', $this->ids((string) $p['id']));
        $wd = trim((string) ($p['wd'] ?? $p['name'] ?? ''));
        if ($wd !== '') $query->whereLike('title', '%' . $this->like($wd) . '%');
        [$order, $sort] = $this->order($p, ['news_id' => 'id', 'news_addtime' => 'published_at', 'news_hits' => 'view_count', 'news_stars' => 'weight'], 'published_at');
        return array_map(fn (array $row): array => $this->frontend->news($row) + ['news_link' => ff_url('news', (int) $row['id'])],
            $query->order($order, $sort)->order('id', 'desc')->limit($this->limit($p, 20))->select()->toArray());
    }

    /** @return array<int, array<string, mixed>> */
    public function specials(string $tag = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_topics')->whereNull('deleted_at')->where('status', 'published');
        if (!empty($p['cid'])) $query->whereIn('category_id', $this->ids((string) $p['cid']));
        if (!empty($p['id'])) $query->whereIn('id', $this->ids((string) $p['id']));
        return array_map(fn (array $row): array => $this->frontend->special($row) + ['special_link' => ff_url('special', (int) $row['id'])],
            $query->order('published_at', 'desc')->order('id', 'desc')->limit($this->limit($p, 20))->select()->toArray());
    }

    /** @return array<int, array<string, mixed>> */
    public function people(string $tag = '', string $kind = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_people')->whereNull('deleted_at')->where('status', 'published');
        if ($kind !== '') $query->where('kind', $kind);
        if (!empty($p['id'])) $query->whereIn('id', $this->ids((string) $p['id']));
        $wd = trim((string) ($p['wd'] ?? $p['name'] ?? ''));
        if ($wd !== '') $query->whereLike('name', '%' . $this->like($wd) . '%');
        return array_map(fn (array $row): array => $this->frontend->person($row) + ['person_link' => ff_url('person', (int) $row['id'])],
            $query->order('updated_at', 'desc')->order('id', 'desc')->limit($this->limit($p, 20))->select()->toArray());
    }

    /** @return array<int, array<string, mixed>> */
    public function tags(string $tag = ''): array
    {
        $p = $this->parse($tag);
        $query = Db::table('ffx_tags');
        if (!empty($p['scope'])) $query->where('scope', (string) $p['scope']);
        return array_map(static fn (array $row): array => $row + ['tag_id' => (int) $row['id'], 'tag_name' => (string) $row['name'], 'tag_list' => (string) $row['scope'], 'tag_count' => 0],
            $query->order('id', 'desc')->limit($this->limit($p, 50))->select()->toArray());
    }

    /** @return array<int, array<string, mixed>> */
    public function slides(string $tag = ''): array
    {
        $p = $this->parse($tag);
        return Db::table('ffx_slides')->where('status', 'enabled')->order('sort_order')->order('id')->limit($this->limit($p, 10))->select()->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    public function navigation(string $tag = ''): array
    {
        $p = $this->parse($tag);
        return Db::table('ffx_navigation')->where('status', 'enabled')->order('sort_order')->order('id')->limit($this->limit($p, 50))->select()->toArray();
    }

    /** @return array<string, string> */
    private function parse(string $tag): array
    {
        $result = [];
        foreach (explode(';', trim($tag, "; \t\n\r\0\x0B")) as $part) {
            if (!str_contains($part, ':')) continue;
            [$key, $value] = explode(':', $part, 2);
            $key = strtolower(trim($key));
            if ($key !== '') $result[$key] = trim($value);
        }
        return $result;
    }

    /** @return array<int, int> */
    private function ids(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $value) ?: []), static fn (int $id): bool => $id > 0)));
    }

    private function limit(array $params, int $default): int
    {
        $raw = (string) ($params['limit'] ?? $default);
        $last = str_contains($raw, ',') ? substr($raw, strrpos($raw, ',') + 1) : $raw;
        return max(1, min(200, (int) $last));
    }

    /** @param array<string, string> $map @return array{string, string} */
    private function order(array $params, array $map, string $default): array
    {
        $requested = trim(explode(',', (string) ($params['order'] ?? ''))[0]);
        return [$map[$requested] ?? $default, strtolower((string) ($params['sort'] ?? 'desc')) === 'asc' ? 'asc' : 'desc'];
    }

    private function like(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], mb_substr(trim($value), 0, 100));
    }

    private function contentType(string $sid): string
    {
        return match ($sid) { '2' => 'article', '3' => 'topic', '8', '9' => 'person', default => 'media' };
    }
}
