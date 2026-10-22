<?php
declare(strict_types=1);
namespace app\service;

use think\exception\HttpException;
use think\facade\Db;

/** Shared filters for page statistics and each resumable metadata request. */
final class DoubanBatchScope
{
    public function categories(): array
    {
        return Db::table('ffx_categories')->where('content_type', 'media')->whereNull('deleted_at')
            ->field('id,parent_id,name')->order('sort_order')->order('id')->select()->toArray();
    }

    public function normalize(array $input, bool $snapshot = false): array
    {
        $category = max(0, (int) ($input['category_id'] ?? 0));
        $period = (string) ($input['period'] ?? 'all');
        if (!in_array($period, ['all', 'day', 'week'], true)) throw new HttpException(422, '更新时间范围无效');
        $scope = (string) ($input['scope'] ?? 'missing');
        if (!in_array($scope, ['missing', 'all'], true)) throw new HttpException(422, '处理范围无效');
        $categories = $this->categories();
        $ids = [];
        if ($category > 0) {
            if (!in_array($category, array_map('intval', array_column($categories, 'id')), true)) throw new HttpException(422, '所选目录不存在或不是视频目录');
            $ids = [$category];
            do {
                $count = count($ids);
                foreach ($categories as $row) {
                    if (in_array((int) $row['parent_id'], $ids, true) && !in_array((int) $row['id'], $ids, true)) $ids[] = (int) $row['id'];
                }
            } while (count($ids) !== $count);
        }
        $started = (string) ($input['batch_started_at'] ?? '');
        if ($started === '') $started = gmdate('Y-m-d H:i:s');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $started, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $started || $date->getTimestamp() > time() + 60) throw new HttpException(422, '批次开始时间无效，请重置进度');
        $since = $period === 'all' ? null : gmdate('Y-m-d H:i:s', $date->getTimestamp() - ($period === 'day' ? 86400 : 604800));
        $upperId = $snapshot ? max(0, (int) ($input['upper_id'] ?? 0)) : 0;
        if ($snapshot && $upperId === 0) $upperId = (int) Db::table('ffx_media')->max('id');
        return ['category_id' => $category, 'category_ids' => $ids, 'period' => $period, 'scope' => $scope,
            'batch_started_at' => $started, 'since' => $since, 'upper_id' => $upperId, 'snapshot' => $snapshot];
    }

    public function query(array $filters, bool $requireId = true): \think\db\Query
    {
        $query = Db::table('ffx_media')->whereNull('deleted_at');
        if ($requireId) $query->whereRaw("douban_id REGEXP '^[1-9][0-9]{4,11}$'");
        if ($filters['category_id'] > 0) $query->whereIn('category_id', $filters['category_ids']);
        if ($filters['since'] !== null) $query->where('updated_at', '>=', $filters['since'])->where('updated_at', '<=', $filters['batch_started_at']);
        if ($filters['snapshot']) $query->where('id', '<=', $filters['upper_id']);
        return $query;
    }

    public function candidates(array $filters): \think\db\Query
    {
        return $this->query($filters)->whereRaw("(COALESCE(TRIM(poster_url),'')='' OR COALESCE(TRIM(content),'')='' OR COALESCE(TRIM(imdb_id),'')='' OR release_date IS NULL OR COALESCE(rating,0)=0 OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.director')),'')='' OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.actor')),'')='')");
    }
}
