<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Episode extends Model
{
    protected $table = 'ffx_episodes';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'media_id' => 'integer',
        'source_id' => 'integer',
        'season_id' => 'integer',
        'episode_no' => 'integer',
        'duration_seconds' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'json',
    ];

    protected $jsonAssoc = true;
}
