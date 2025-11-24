<?php

namespace app\admin\model\developer;

use think\Model;


/**
 * 插件开发
 * <!--Developer-->
 * Class Plugin
 * @package app\admin\model\developer
 */
class Plugin extends Model
{

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = false;

    /**
     * 转换小写字母
     * @param $value
     * @return mixed
     */
    public function setNameAttr($value): string
    {
        return strtolower($value);
    }


}