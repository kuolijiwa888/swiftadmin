<?php
return [
    //备份文件存储位置
	'path' => '/backup/database/',
    //文件大小
    'part' => 20971520,
    //是否开启压缩
    'compress' => 1,
    //压缩级别
    'level' => 7,
    //备份时忽略的表
    'ignore' => 'sa_admin_log,sa_system_log,sa_user_log',
];