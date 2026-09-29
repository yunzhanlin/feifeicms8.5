<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

final class Admin extends Model
{
    protected $table = 'ffx_admins';
    protected $pk = 'id';
    protected $autoWriteTimestamp = false;

    protected $hidden = ['password_hash'];
}
