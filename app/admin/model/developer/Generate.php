<?php
declare (strict_types = 1);

namespace app\admin\model\developer;

use think\Model;

/**
 * <!--Developer-->
 * @mixin \think\Model
 */
class Generate extends Model
{
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}
