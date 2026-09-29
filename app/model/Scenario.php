<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Scenario extends Model
{
    protected $table = 'ffx_scenarios';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'media_id' => 'integer',
        'episode_no' => 'integer',
        'sort_order' => 'integer',
    ];

    public function media()
    {
        return $this->belongsTo(Media::class, 'media_id', 'id');
    }
}
