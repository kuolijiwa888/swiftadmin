<?php

namespace app\admin\validate\developer;

use think\Validate;

/**
 * <!--Developer-->
 */
class Plugin extends Validate
{

    /**
     * 定义验证规则
     * 格式：'字段名'    =>    ['规则1','规则2'...]
     *
     * @var array
     */
    protected $rule = [
        'name|标识'    => 'require|min:3|max:32|alphaNum|name_initial',
        'version|版本' => 'require|min:5|max:10|filters',
    ];

    /**
     * 定义错误信息
     * 格式：'字段名.规则名'    =>    '错误信息'
     *
     * @var array
     */
    protected $message = [
        'name.require'      => '标识不能为空',
        'name.min'          => '标识不能少于3个字符',
        'name.max'          => '标识不能超过32个字符',
        'name.alphaNum'     => '标识只能是字母和数字',
        'name.name_initial' => '标识不能以数字开头',
        'version.min'       => '版本不能少于5个字符',
        'version.filters'   => '插件版本版本号应为1.0.0或1.0.1格式',
    ];


    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => [],
        'edit' => [],
    ];

    /**
     * 验证版本是否符合格式
     * @param $value
     * @return bool
     */
    public function filters($value): bool
    {
        if (!preg_match('/^[1-9]\.[0-9]\.[0-9]$/i', $value)) {
            return false;
        }
        return true;
    }

    /**
     * 验证 插件标识规则
     * @param $value
     * @return bool
     */
    public function name_initial($value): bool
    {
        if (is_numeric($value[0])) {
            return false;
        }
        return true;
    }
}
