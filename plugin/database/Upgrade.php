<?php

namespace plugin\database;

/**
 * 数据库管理插件
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