<?php
return [
  0 => [
    'title' => '数据备份',
    'router' => '/database/index',
    'icon' => 'fa-database',
    'auth' => 1,
    'type' => 0,
    'children' => [
      0 => [
        'title' => '备份配置',
        'router' => '/database/Index/config',
        'icon' => NULL,
        'auth' => 1,
        'type' => 1,
      ],
    ],
  ],
];