<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Media extends Model
{
    protected $table = 'ffx_media';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'category_id' => 'integer',
        'release_year' => 'integer',
        'episode_total' => 'integer',
        'is_completed' => 'boolean',
        'price_points' => 'integer',
        'rating' => 'float',
        'rating_count' => 'integer',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'dislike_count' => 'integer',
        'weight' => 'integer',
        'metadata' => 'json',
    ];

    protected $jsonAssoc = true;

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }

    public function sources()
    {
        return $this->hasMany(PlaySource::class, 'media_id', 'id')
            ->where('status', 'enabled')
            ->order('sort_order', 'asc')
            ->order('id', 'asc');
    }

    public function scenarios()
    {
        return $this->hasMany(Scenario::class, 'media_id', 'id')
            ->whereNull('deleted_at')
            ->order('sort_order', 'asc')
            ->order('episode_no', 'asc');
    }
}
