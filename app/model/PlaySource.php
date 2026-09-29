<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class PlaySource extends Model
{
    protected $table = 'ffx_play_sources';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'media_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function episodes()
    {
        return $this->hasMany(Episode::class, 'source_id', 'id')
            ->where('status', 'enabled')
            ->order('sort_order', 'asc')
            ->order('episode_no', 'asc');
    }
}
