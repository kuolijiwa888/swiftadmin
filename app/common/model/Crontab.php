<?php

namespace app\common\model;

use think\Model;


/**
 * crontab
 * <!---->
 * 定时任务
 * Class Crontab
 * @package app\common\model
 */
class Crontab extends Model
{

    

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    

        public function setContentAttr($value)
    {
        if (!empty($value)) {
            $value = serialize($value);
        }

        return $value;
    }

    public function getContentAttr($value)
    {
        if (!empty($value)) {
            $value = unserialize($value);
        }

        return $value;
    }

    /**
     * 设置开始时间（将日期时间字符串转换为时间戳）
     * @param mixed $value
     * @return int|null
     */
    public function setBegintimeAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果已经是时间戳，直接返回
        if (is_numeric($value)) {
            return (int)$value;
        }
        
        // 将日期时间字符串转换为时间戳
        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * 获取开始时间（将时间戳转换为日期时间字符串）
     * @param mixed $value
     * @return string|null
     */
    public function getBegintimeAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        return date('Y-m-d H:i:s', $value);
    }

    /**
     * 设置结束时间（将日期时间字符串转换为时间戳）
     * @param mixed $value
     * @return int|null
     */
    public function setEndtimeAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        // 如果已经是时间戳，直接返回
        if (is_numeric($value)) {
            return (int)$value;
        }
        
        // 将日期时间字符串转换为时间戳
        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    /**
     * 获取结束时间（将时间戳转换为日期时间字符串）
     * @param mixed $value
     * @return string|null
     */
    public function getEndtimeAttr($value)
    {
        if (empty($value)) {
            return null;
        }
        
        return date('Y-m-d H:i:s', $value);
    }

}