<?php
declare(strict_types=1);

namespace app\service;

use app\model\Media;

final class AdminVodQuery
{
    /** @param array<string, mixed> $filters */
    public function build(array $filters): mixed
    {
        $orderAliases = ['id' => 'id', 'hits' => 'view_count', 'year' => 'release_year', 'addtime' => 'updated_at', 'stars' => 'weight', 'updated_at' => 'updated_at', 'weight' => 'weight', 'rating' => 'rating'];
        $order = isset($orderAliases[$filters['order']]) ? (string) $filters['order'] : 'id';
        $sort = ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query = Media::whereNull('deleted_at')->order($orderAliases[$order], $sort)->order('id', $sort);

        $keyword = (string) ($filters['wd'] ?? '');
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->whereRaw("(title LIKE ? OR subtitle LIKE ? OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.actor')), '') LIKE ? OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.director')), '') LIKE ?)", [$like, $like, $like, $like]);
        }
        if ((int) ($filters['category_id'] ?? 0) > 0) $query->where('category_id', (int) $filters['category_id']);
        if (($filters['status'] ?? '') !== '') $query->where('status', (string) $filters['status']);
        if ((int) ($filters['year'] ?? 0) > 0) $query->where('release_year', (int) $filters['year']);
        if ((int) ($filters['stars'] ?? 0) > 0) $query->where('weight', (int) $filters['stars']);
        if (($filters['isend'] ?? '') === 'true') $query->where('is_completed', 1);
        elseif (($filters['isend'] ?? '') === 'false') $query->where('is_completed', 0);
        if (($filters['weekday'] ?? '') !== '') $query->where('admin_weekday', (string) $filters['weekday']);
        if (($filters['state'] ?? '') !== '') $query->where('admin_state', (string) $filters['state']);
        if (($filters['area'] ?? '') !== '') $query->where('area', (string) $filters['area']);
        if (($filters['type'] ?? '') !== '') $query->whereRaw("FIND_IN_SET(?, REPLACE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type')), ''), '，', ',')) > 0", [(string) $filters['type']]);
        if (($filters['play'] ?? '') !== '') $query->whereRaw("EXISTS (SELECT 1 FROM ffx_play_sources ps WHERE ps.media_id=ffx_media.id AND ps.status='enabled' AND ps.source_key=?)", [(string) $filters['play']]);
        if (in_array($filters['access'] ?? '', ['member', 'points', 'free'], true)) $query->where('access_mode', (string) $filters['access']);

        match ($filters['missing'] ?? '') {
            'poster' => $query->whereRaw("COALESCE(TRIM(poster_url), '') = ''"),
            'play' => $query->whereRaw("NOT EXISTS (SELECT 1 FROM ffx_episodes ep INNER JOIN ffx_play_sources ps ON ps.id = ep.source_id WHERE ep.media_id = ffx_media.id AND ep.status = 'enabled' AND ps.status = 'enabled' AND TRIM(ep.media_url) <> '')"),
            'douban' => $query->whereRaw("(douban_id = '' OR poster_url = '' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.director')), '') = '' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.actor')), '') = '')"),
            'scenario' => $query->whereRaw('(COALESCE(episode_total, 0) > 0 OR EXISTS (SELECT 1 FROM ffx_episodes ep WHERE ep.media_id = ffx_media.id))')
                ->whereRaw('NOT EXISTS (SELECT 1 FROM ffx_scenarios sc WHERE sc.media_id = ffx_media.id AND sc.deleted_at IS NULL)'),
            default => null,
        };

        match ($filters['meta'] ?? '') {
            'lines' => $query->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.lines')), '') <> ''"),
            'scenario' => $query->whereRaw('EXISTS (SELECT 1 FROM ffx_scenarios sc WHERE sc.media_id = ffx_media.id AND sc.deleted_at IS NULL)'),
            'douban' => $query->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'"),
            'trysee' => $query->whereRaw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.trysee')) AS UNSIGNED), 0) > 0"),
            'series' => $query->where('admin_series', '<>', ''),
            default => null,
        };

        if (($filters['duplicate'] ?? '') === 'title') {
            $query->whereRaw('title IN (SELECT title FROM ffx_media WHERE deleted_at IS NULL GROUP BY title HAVING COUNT(*) > 1)');
        } elseif (($filters['duplicate'] ?? '') === 'douban') {
            $query->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'")->whereRaw("douban_id IN (SELECT douban_id FROM ffx_media WHERE deleted_at IS NULL AND douban_id REGEXP '^[1-9][0-9]{4,11}$' GROUP BY douban_id HAVING COUNT(*) > 1)");
        }
        if (!empty($filters['locked'])) $query->where('admin_inputer', 'feifeicms');
        if (!empty($filters['paid'])) $query->where('price_points', '>', 0);
        return $query;
    }
}
