<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Player extends Model
{
    protected $name = 'player';
    protected $pk = 'player_id';
    protected $autoWriteTimestamp = false;
}
