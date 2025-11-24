<?php
return [
  0 => [
    'title' => '开发助手',
    'router' => '/developer',
    'icon' => 'fa-plug',
    'auth' => 1,
    'type' => 0,
    'children' => [
      0 => [
        'title' => '插件开发',
        'router' => '/developer/Plugin/index',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      1 => [
        'title' => '代码生成',
        'router' => '/developer/Generate/index',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
    ],
  ],
  1 => [
    'title' => '开发示例',
    'router' => '/developer/Example/',
    'icon' => 'fa-gitlab',
    'auth' => 1,
    'type' => 0,
    'children' => [
      0 => [
        'title' => '基础表单',
        'router' => '/developer/Example/index',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      1 => [
        'title' => '数据表格',
        'router' => '/developer/Example/table',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      2 => [
        'title' => '卡片列表',
        'router' => '/developer/Example/card',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      3 => [
        'title' => '统计图表',
        'router' => '/developer/Example/echarts',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      4 => [
        'title' => '组件示例',
        'router' => '/developer/Example/component',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      5 => [
        'title' => '文本编辑器',
        'router' => '/developer/Example/editor',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
      6 => [
        'title' => '常规辅助元素',
        'router' => '/developer/Example/auxiliar',
        'icon' => '',
        'auth' => 1,
        'type' => 0,
      ],
    ],
  ],
];