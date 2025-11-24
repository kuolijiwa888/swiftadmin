<?php

namespace plugin\developer;

/**
 * 开发助手
 * 升级脚本
 */
class Upgrade
{
    /**
     * 插件升级方法
     * @access public
     * @param $oldVersion
     * @param $newVersion
     * @return bool
     */
    public function execute($oldVersion, $newVersion): bool
    {
        return true;
    }
}
