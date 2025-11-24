<?php
declare (strict_types = 1);

namespace app\admin\validate\developer;

use think\Validate;

/**
 * <!--Developer-->
 */
class Generate extends Validate
{
    /**
     * 定义验证规则
     * 格式：'字段名'	=>	['规则1','规则2'...]
     *
     * @var array
     */	
    protected $rule =   [
        'menus'  => 'rules',
    ];
    
    /**
     * 定义错误信息
     * 格式：'字段名.规则名'	=>	'错误信息'
     *
     * @var array
     */	
    protected $message  =   [
        'menus.rules'  => '路由规则匹配错误',		
    ];
	
	/**
     * 自定义验证规则
     *
     * @param [type] $value
     * @param [type] $rule
     * @param [type] $post
     * @return bool
     */
    protected function rules($value, $rule, $post): bool
    {
        if (!empty($value)) {
            $controller = $post['controller'];
            $controller = substr($controller,1,strrpos($controller,'/')-1);
            $list = unserialize($value);
            foreach ($list as $value) {
                $router = $value['router'];
                $router = substr($router,1,strrpos($router,'/')-1);
                if ($router != $controller) {
                    $this->message['menus.rules'] = $router.' regex error';
                    return false;
                }
            }
        }

        return true;
    }	
}
