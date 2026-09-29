<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Category extends Model
{
    protected $table = 'ffx_categories';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'filter_options' => 'json',
    ];

    protected $jsonAssoc = true;
}
