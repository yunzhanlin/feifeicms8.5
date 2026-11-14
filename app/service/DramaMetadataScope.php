<?php
declare(strict_types=1);

namespace app\service;

use think\db\Query;
use think\facade\Db;

final class DramaMetadataScope
{
    public const MEDIA_FIELDS = 'id,title,original_title,release_year,episode_total,imdb_id';

    public function filters(array $input): array
    {
        return [
            'wd' => mb_substr(trim((string) ($input['wd'] ?? '')), 0, 100),
            'category_id' => max(0, (int) ($input['category_id'] ?? 0)),
            'scope' => ($input['scope'] ?? '') === 'missing' ? 'missing' : 'all',
        ];
    }

    public function query(array $filters): Query
    {
        $query = Db::table('ffx_media')->whereNull('deleted_at');
        if ($filters['wd'] !== '') {
            $like = '%' . $filters['wd'] . '%';
            $query->whereRaw('(title LIKE ? OR original_title LIKE ?)', [$like, $like]);
        }
        if ($filters['category_id'] > 0) {
            $category = $filters['category_id'];
            $query->whereRaw('(category_id = ? OR EXISTS (SELECT 1 FROM ffx_media_categories mc WHERE mc.media_id=ffx_media.id AND mc.category_id=?))', [$category, $category]);
        }
        if ($filters['scope'] === 'missing') {
            $query->whereRaw("(NOT EXISTS (SELECT 1 FROM ffx_media_people mp WHERE mp.media_id=ffx_media.id AND mp.credit_type='actor')
                OR NOT EXISTS (SELECT 1 FROM ffx_media_people mp WHERE mp.media_id=ffx_media.id AND (mp.credit_type='character' OR (mp.credit_type='actor' AND mp.character_name<>'')))
                OR (COALESCE(episode_total,0)>1 AND episode_total > (SELECT COUNT(*) FROM ffx_scenarios sc WHERE sc.media_id=ffx_media.id AND sc.deleted_at IS NULL)))");
        }
        return $query;
    }
}
