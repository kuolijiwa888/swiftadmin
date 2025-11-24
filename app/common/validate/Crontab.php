<?php

namespace app\common\validate;

use think\Validate;
/**
  * <!---->
  * Crontab 验证器
  * Class Crontab
  * @package app\common\validate
  */
class Crontab extends Validate
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
