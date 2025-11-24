<?php

namespace plugin\database;

use app\PluginController;

/**
 * 数据库管理插件
 */
class Database extends PluginController
{
    /**
     * 插件安装方法
     * @return bool
     */
    public function install()
    {
        return true;
    }

    /**
     * 插件卸载方法
     * @return bool
     */
    public function uninstall()
    {
        return true;
    }

    /**
     * 插件启用方法
     * @return bool
     */
    public function enabled()
    {
        return true;
    }

    /**
     * 插件禁用方法
     * @return bool
     */
    public function disabled()
    {

        return true;
    }

    /**
     * 插件初始化
     * @return bool
     */
    public function appInit() 
	{}
}
