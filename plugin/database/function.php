<?php

/**
 * Database插件公共函数库
 */

if (!function_exists('format_time')) {
    /*
     * 格式化时间戳
     */
    function format_time()
    {
        return date('Y-m-d H:i:s', time());
    }
}
if (!function_exists('is_admin')) {
    /*
     * 判断是不是管理员
     */
    function is_admin()
    {
        return (new \app\admin\service\AuthService())->SuperAdmin();
    }
}
