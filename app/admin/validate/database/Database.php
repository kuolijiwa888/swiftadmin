<?php

namespace app\admin\validate\database;

use think\Validate;
/**
 * 数据库管理 验证器
 * <!--Database-->
 */
class Database extends Validate
{
    /**
     * 验证规则
     */
    protected $rule = [
    ];

    
    /**
     * 提示消息
     */
    protected $message = [
    ];


    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => [],
        'edit' => [],
    ];
    
}
