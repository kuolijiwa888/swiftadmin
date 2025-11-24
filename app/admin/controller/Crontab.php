<?php
declare (strict_types = 1);
namespace app\admin\controller;

use app\AdminController;
use Webman\Http\Request;
use app\common\model\Crontab as CrontabModel;

/**
 * crontab
 * 定时任务
 * <!---->
 * Class Crontab
 * @package app\admin\controller
 */
class Crontab extends AdminController
{
    /**
     * Crontab模型对象
     * @var \app\common\model\Crontab
     */

    public function __construct()
    {
        parent::__construct();
        $this->model = new CrontabModel;
    }

    /**
     * 默认生成的方法为index/add/edit/del/status 五个方法
     * 当创建CURD的时候，DIY的函数体和模板为空，请自行编写代码
     */
    


}
