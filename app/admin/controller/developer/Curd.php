<?php

declare(strict_types=1);
// +----------------------------------------------------------------------
// | swiftAdmin 极速开发框架 [基于WebMan开发]
// +----------------------------------------------------------------------
// | Copyright (c) 2020-2030 http://www.swiftadmin.net
// +----------------------------------------------------------------------
// | swiftAdmin.net High Speed Development Framework
// +----------------------------------------------------------------------
// | Author: meystack <coolsec@foxmail.com> Apache 2.0 License
// +----------------------------------------------------------------------
namespace app\admin\controller\developer;

use app\admin\model\developer\Generate;
use app\AdminController;
use app\common\model\system\AdminRules;
use system\Form;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\helper\Str;

/**
 * 一键CURD管理
 * <!--Developer-->
 * Class Curd
 * @package app\admin\controller\developer
 */
class Curd extends AdminController
{
    /**
     * 数据表前缀
     *
     * @var mixed
     */
    public mixed $prefix = 'sa_';

    /**
     * 获取菜单
     *
     * @var array
     */
    public array $menus = [];

    /**
     * 关联表信息
     *
     * @var array
     */
    public array $relation = [];

    /**
     * 函数体
     *
     * @var array
     */
    public array $methods = [];

    /**
     * 模板路径
     *
     * @var string
     */
    public string $templatePath = '';

    /**
     * 模板文件
     *
     * @var array
     */
    public array $templateFiles = [];

    /**
     * 添加时间字段
     * @var string
     */
    protected string $createTimeField = 'create_time';

    /**
     * 更新时间字段
     * @var string
     */
    protected string $updateTimeField = 'update_time';

    /**
     * 软删除时间字段
     * @var string
     */
    protected string $deleteTimeField = 'delete_time';

    /**
     * 过滤默认模板
     *
     * @var array
     */
    public array $filterMethod = ['index', 'add', 'edit', 'del', 'status'];

    /**
     * 限定特定组件
     *
     * @var array
     */
    public array $mustbeComponent = ['set', 'text', 'json'];

    /**
     * 修改器字段
     *
     * @var array
     */
    public array $modifyFieldAttr = ['set', 'text', 'json'];

    /**
     * 保留字段
     *
     * @var string
     */
    public string $keepField = 'status';

    /**
     * 查询字段[SELECT]
     *
     * @var array
     */
    public array $dropdown = ['radio', 'checkbox', 'select'];

    /**
     * COLS换行符
     *
     * @var string
     */
    public string $commaEol = ',' . PHP_EOL;

    /**
     * 受保护的表
     * 禁止CURD操作
     * @var array
     */
    protected array $protectTable = [
        "admin", "admin_access", "admin_group", "admin_rules", "company", "department",
        "dictionary", "generate", "jobs", "user", "user_group", "user_third", "user_validate"
    ];

    /**
     * 类保留关键字
     *
     * @var array
     */
    protected array $internalKeywords = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else',
        'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile',
        'eval', 'exit', 'extends', 'final', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'namespace', 'new',
        'or', 'print', 'private', 'protected', 'public', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield', 'readonly', 'match', 'fn'
    ];

    // 初始化操作
    public function __construct()
    {
        parent::__construct();
        $this->model = new Generate();
        $this->prefix = function_exists('get_env') ? get_env('DATABASE_PREFIX') : getenv('DATABASE_PREFIX');
    }

    /**
     * 生成CURD代码
     * @return \support\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function build(): \support\Response
    {
        $id = input('id');
        $data = $this->model->find($id);

        if ($data['status'] && !$data['force']) {
            return $this->error('该表已经生成过了');
        }

        $table = str_replace($this->prefix, '', $data['table']);
        if ($this->filterSystemTable($table)) {
            return $this->error('禁止操作系统表');
        }

        // 命名空间
        $replaces = [];
        $controller = $data['controller'];
        $module = $data['global'] ? 'common' : 'admin';
        $element = ['controller', 'model', 'validate'];

        try {

            foreach ($element as $key => $item) {
                $result = $this->parseNameData($item == 'controller' ? 'admin' : $module, $controller, $key ? $table : '', $item);
                list($replaces[$item . 'Name'], $replaces[$item . 'Namespace'], $replaces[$item . 'File']) = $result;
            }

            $this->getTemplatePath($controller);
            list($this->menus, $this->methods, $this->templateFiles) = $this->getMenuMethods($data->toArray());

            // 获取字段
            $adviceField = [];
            $adviceSearch = [];
            $everySearch = [];

            // 字段属性值
            $colsFields = [];
            $fieldAttrArr = [];

            // 表单设计
            $formDesign = [];
            $formItem = [];
            $formType = $data['formType'];
            if (!empty($data['formDesign'])) {
                $formDesign = json_decode($data['formDesign'], true);
            }

            $this->tableFields = Db::name($table)->getFields();
            $listFields = explode(',', $data['listField']);
            foreach ($this->tableFields as $key => $value) {
                $field = $value['name'];
                $comment = str_replace(':', ';', $value['comment']);
                if (empty($comment)) {
                    return $this->error($field . " 字段注释不能为空");
                }

                $this->tableFields[$key]['title'] = explode(';', $comment)[0];

                // 是否存在状态字段
                if ($field == $this->keepField) {
                    $adviceSearch[] = $field;
                }

                // 获取字段类型
                $everySearch[] = $field;
                $type = explode('(', $value['type'])[0];

                // 限定组件类型
                if (in_array($type, $this->mustbeComponent)) {
                    $this->validComponent($field, $type, $formDesign);
                }

                if (in_array($type, $this->modifyFieldAttr)) {
                    $fieldAttrArr[] = $this->getFieldAttrArr($field, $type);
                }

                if (empty($adviceField)
                    || ($adviceField['type'] != 'varchar' && $type == 'varchar')) {
                    $adviceField = [
                        'field' => $field,
                        'type'  => $type,
                    ];
                }

                if (in_array($field, $listFields)) {
                    $colsFields[] = [
                        'field' => $field,
                        'title' => '{:__("' . $this->tableFields[$key]['title'] . '")}',
                    ];
                }
            }

            // 推荐搜索片段
            $adviceSearch[] = $adviceField['field'];
            $adviceSearchHtml = $this->getAdviceSearch($adviceSearch, $formDesign);

            // 获取全部搜索字段
            $everySearch = array_diff($everySearch, $adviceSearch);
            $everySearchHtml = $this->getAdviceSearch($everySearch, $formDesign);
            $controller = substr($controller, 0, (strrpos($controller, '/') + 1));
            $colsListArr = $this->getColsListFields($colsFields, $formDesign, $this->tableFields);

            $replaces['table'] = $table;
            $replaces['title'] = $data['title'];
            $replaces['pluginClass'] = $data['plugin'];
            $replaces['controller'] = $controller;
            $replaces['controllerDiy'] = $this->getMethodString($this->methods);
            $replaces['colsListArr'] = $colsListArr;
            $replaces['fieldAttrArr'] = implode(PHP_EOL . PHP_EOL, $fieldAttrArr);
            $replaces['adviceSearchHtml'] = $adviceSearchHtml;
            $replaces['everySearchHtml'] = $everySearchHtml;
            $replaces['relationMethodList'] = $this->getRelationMethodList($data['relation']);
            $replaces['FormArea'] = $data['width'] . ',' . $data['height'];
            $replaces['softDelete'] = array_key_exists($this->deleteTimeField, $this->tableFields) ? "use SoftDelete;" : '';
            $replaces['softDeleteClassPath'] = array_key_exists($this->deleteTimeField, $this->tableFields) ? "use think\model\concern\SoftDelete;" : '';
            $replaces['createTime'] = array_key_exists($this->createTimeField, $this->tableFields) ? "'$this->createTimeField'" : 'false';
            $replaces['updateTime'] = array_key_exists($this->updateTimeField, $this->tableFields) ? "'$this->updateTimeField'" : 'false';
            $replaces['deleteTime'] = array_key_exists($this->deleteTimeField, $this->tableFields) ? "'$this->deleteTimeField'" : 'false';

            // 生成控制器/模型/验证器规则
            foreach ($element as $index => $item) {
                if ($index == 0
                    && (!$data['create'] || !$data['listField'])) {
                    continue;
                }

                $code = read_file($this->getStubTpl($item));
                foreach ($replaces as $key => $value) {
                    $code = str_replace("{%$key%}", $value, $code);
                }

                write_file($replaces[$item . 'File'], $code);
            }

            // 生成表单元素
            $template = $formType ? 'add' : 'inside';
            $formHtml = read_file($this->getStubTpl($template));
            if (!empty($formDesign) && $data['listField']) {
                
                // 修正 formDesign 中的字段名：将临时字段名（如 select_2）替换为正确的数据库字段名
                $formDesign = $this->correctFormDesignFieldNames($formDesign, $this->tableFields);

                foreach ($formDesign as $key => $value) {
                    $formItem[$key] = Form::itemElem($value, $formType);
                }

                $formItem = implode(PHP_EOL, $formItem);
                $formHtml = str_replace(['{formItems}', '{pluginClass}'], [$formItem, $replaces['pluginClass']], $formHtml);
                $formType && write_file($this->templatePath . 'add.html', $formHtml);
            }

            // 生成首页模板
            $indexHtml = read_file($this->getStubTpl($formType ? 'index' : 'index_inside'));
            if (!empty($data['listField'])) {
                $replaces['editforms'] = $formType ? '' : $formHtml;
                foreach ($replaces as $key => $value) {
                    $indexHtml = str_replace("{%$key%}", $value, $indexHtml);
                }
                if (empty($formDesign)) {
                    $indexHtml = preg_replace('/<!--formBegin-->(.*)<!--formEnd-->/isU', '', $indexHtml);
                }
                write_file($this->templatePath . 'index.html', str_replace('{pluginClass}', $replaces['pluginClass'], $indexHtml));
            }

            // 生成扩展模板
            $extendHtml = read_file($this->getStubTpl('extend'));
            $extendHtml = str_replace('{pluginClass}', $replaces['pluginClass'], $extendHtml);
            foreach ($this->methods as $method) {
                write_file($this->templatePath . Str::snake($method) . '.html', $extendHtml);
            }

            // 生成CURD菜单
            if ($data['create'] && !empty($data['listField'])) {
                AdminRules::createMenu([$this->menus], $replaces['pluginClass'] ?: $table, $data['pid']);
            }

            // 更新生成状态
            $data->save(['status' => 1]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        return $this->success('生成成功');
    }

    /**
     * 清理内容
     * @return mixed|void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function clear()
    {
        $id = input('id');
        if (request()->isAjax()) {

            $data = $this->model->find($id);
            $table = str_replace($this->prefix, '', $data['table']);
            $controller = $data['controller'];
            try {

                $module = $data['global'] ? 'common' : 'admin';
                $element = ['controller', 'model', 'validate'];
                foreach ($element as $key => $item) {
                    $result = $this->parseNameData($item == 'controller' ? 'admin' : $module, $controller, $key ? $table : '', $item);
                    $file = end($result);
                    if (file_exists($file)) {
                        unlink($file);
                    }
                    // 删除空文件夹
                    remove_empty_dir(dirname($file));
                }

                list($this->menus, $this->methods, $this->templateFiles) = $this->getMenuMethods($data->toArray());
                recursive_delete($this->getTemplatePath($controller));
                AdminRules::disabled($table, true);
                $data->save(['status' => 0]);
            } catch (\Throwable $th) {
                return $this->error($th->getMessage());
            }

            return $this->success('删除成功');
        }
    }

    /**
     * 获取列表字段
     * @param array $colsFields
     * @param array $formDesign
     * @param array $tableFields
     * @return string
     */
    public function getColsListFields(array $colsFields = [], array $formDesign = [], array $tableFields = []): string
    {
        $colsListArr = [];
        foreach ($colsFields as $key => $value) {

            // 过滤删除字段
            $colsLine = [];
            $colsField = $value['field'];
            $colsTitle = $value['title'];
            if ($colsField == $this->deleteTimeField) {
                continue;
            }

            // 获取每一列参数合集
            $colsLine[] = "field:'$colsField'";
            
            // 检查是否是 status 字段且是 enum 类型
            $isStatusEnum = false;
            if ($colsField == $this->keepField) {
                // 检查是否是 enum 类型
                $fieldType = $tableFields[$colsField]['type'] ?? '';
                if (stripos($fieldType, 'enum') !== false) {
                    $isStatusEnum = true;
                } else {
                    $colsLine[] = "templet: '#columnStatus'";
                }
            }

            $item = $this->recursiveComponent($colsField, $formDesign);
            if (!empty($item) && is_array($item)) {
                $colsArr = '';
                $colsTag = $item['tag'];
                if (in_array($colsTag, $this->dropdown)) {
                    $colsArr = $item['options'];
                    // 将选项数组转换为对象映射，方便直接访问
                    $colsArrMap = [];
                    foreach ($colsArr as $index => $elem) {
                        $colsArrMap[$elem['value']] = ['title' => "{:__('" . $elem['title'] . "')}", 'value' => $elem['value']];
                    }
                    $colsArr = json_encode($colsArrMap, JSON_UNESCAPED_UNICODE);
                    $colsTpl = read_file($this->getStubTpl('list/' . $colsTag));
                } else if ($colsTag == 'upload') {
                    $colsTpl = read_file($this->getStubTpl('list/' . $item['uploadtype']));
                } else if ($colsTag == 'date') {
                    // 日期字段使用简单的字符串显示（因为模型已经转换为字符串）
                    $colsTpl = '';
                } else {
                    $colsTpl = read_file($this->getStubTpl('list/' . $colsTag));
                }
                if (!empty($colsTpl)) {
                    $colsLine[] = str_replace(['{colsArr}', '{field}'], [$colsArr, $colsField], $colsTpl);
                }
            } else {
                // 如果没有在 formDesign 中找到，检查字段名是否包含 time 或 date
                $fieldType = $tableFields[$colsField]['type'] ?? '';
                $fieldNameLower = strtolower($colsField);
                if (stripos($fieldType, 'bigint') !== false || stripos($fieldType, 'int') !== false) {
                    if (strpos($fieldNameLower, 'time') !== false || strpos($fieldNameLower, 'date') !== false) {
                        // 时间戳字段，但模型已经转换为字符串，直接显示即可
                        // 不需要特殊处理
                    }
                }
            }
            
            // 如果是 status enum 类型，生成 enum 显示模板
            if ($isStatusEnum) {
                $enumValues = $this->parseEnumValues($tableFields[$colsField]['type'] ?? '');
                if (!empty($enumValues)) {
                    // 构建状态映射，使用中文标题
                    $statusTitles = [
                        'normal' => '正常',
                        'completed' => '已完成',
                        'expired' => '已过期',
                        'hidden' => '已禁用'
                    ];
                    $enumMap = [];
                    foreach ($enumValues as $enumValue) {
                        $title = $statusTitles[$enumValue] ?? $enumValue;
                        $enumMap[] = ['value' => $enumValue, 'title' => "{:__('" . $title . "')}"];
                    }
                    $enumJson = json_encode($enumMap, JSON_UNESCAPED_UNICODE);
                    // 生成简单的显示模板
                    $colsLine[] = "templet:function(d) { var colsArr = $enumJson; var statusMap = {}; colsArr.forEach(function(item) { statusMap[item.value] = item.title; }); return statusMap[d.$colsField] || d.$colsField; }";
                }
            }

            $colsLine[] = "title:'$colsTitle'";
            $colsListArr[$key] = '{' . implode(',', $colsLine) . '}';
        }

        $colsListArr = implode($this->commaEol, $colsListArr);
        return $colsListArr ? $colsListArr . ',' : $colsListArr;
    }

    /**
     * 解析 enum 类型的值
     * @param string $fullType MySQL 字段类型字符串，如 "enum('normal','completed','expired','hidden')"
     * @return array
     */
    protected function parseEnumValues(string $fullType): array
    {
        if (stripos($fullType, 'enum') === false) {
            return [];
        }
        
        // 匹配 enum('value1','value2',...) 格式
        if (preg_match("/enum\s*\(([^)]+)\)/i", $fullType, $matches)) {
            $valuesStr = $matches[1];
            // 分割值，处理引号
            $values = [];
            preg_match_all("/'([^']+)'/", $valuesStr, $valueMatches);
            if (!empty($valueMatches[1])) {
                $values = $valueMatches[1];
            }
            return $values;
        }
        
        return [];
    }

    /**
     * 获取修改器
     * @param string|null $field
     * @param string|null $type
     * @param string $subTpl
     * @return array|false|string|string[]
     */
    public function getFieldAttrArr(string $field = null, string $type = null, string $subTpl = 'change')
    {
        $tplPath = $subTpl . '/' . $type;
        $methods = read_file($this->getStubTpl($tplPath));

        if (!empty($methods)) {
            $methods = str_replace('{%field%}', ucfirst($field), $methods);
        }

        return $methods;
    }

    /**
     * 验证组件
     * @param string|null $field
     * @param string|null $type
     * @param array $data
     * @return mixed
     */
    public function validComponent(string $field, string $type, array $data = [])
    {
        if (!$field || !$data) {
            return false;
        }

        $result = $this->recursiveComponent($field, $data);

        if (!empty($result)) {

            $tag = strtolower($result['tag']);
            switch ($type) {
                case 'set':
                    if ($tag != 'checkbox') {
                        return $this->error($field . ' 组件类型限定为checkbox');
                    }
                    break;
                case 'json':
                    if ($tag != 'json') {
                        return $this->error($field . ' 组件类型限定为json');
                    }
                    break;
                case 'text': // 限定TEXT字段类型必须为多文件上传
                    if ($tag != 'upload' || $result['uploadtype'] != 'multiple') {
                        return $this->error($field . ' 字段类型为text时，组件类型限定为多文件上传');
                    }
                    break;
                default:
                    break;
            }
        }

        return false;
    }

    /**
     * 查找组件
     * @param string $field
     * @param array $data
     * @return mixed
     */
    public function recursiveComponent(string $field = '', array $data = [])
    {
        foreach ($data as $value) {

            if ($field == $value['name']) {
                return $value;
            }

            if (isset($value['children']) && $value['children']) {
                $subElem = $value['children'];
                foreach ($subElem as $child) {
                    $item = $this->recursiveComponent($field, $child['children']);
                    if (!empty($item)) {
                        return $item;
                    }
                }
            }
        }
    }

    /**
     * 搜索模板
     * @param array $searchArr
     * @param array $formArr
     * @return false|string
     */
    public function getAdviceSearch(array $searchArr = [], array $formArr = [])
    {
        if (!$searchArr) {
            return false;
        }

        $varData = '';
        $searchHtml = [];
        foreach ($searchArr as $searchField) {

            if ($searchField == $this->deleteTimeField) {
                continue;
            }

            if ($searchField == $this->keepField) {
                $rhtml = read_file($this->getStubTpl('search/status'));
            } else if (in_array($searchField, [$this->createTimeField, $this->updateTimeField])) {
                $rhtml = read_file($this->getStubTpl('search/datetime'));
            } else {

                $result = $this->recursiveComponent($searchField, $formArr);
                if ($result && in_array($result['tag'], $this->dropdown)) {
                    $varData = Form::validOptions($result['options']);
                    $rhtml = read_file($this->getStubTpl('search/select'));
                } else if ($result && in_array($result['tag'], ['slider'])) {
                    $rhtml = read_file($this->getStubTpl('search/slider'));
                    $rhtml = str_replace(
                        ['{default}', '{theme}', '{step}', '{max}', '{min}'],
                        [$result['data_default'], $result['data_theme'], $result['data_step'], $result['data_max'], $result['data_min']],
                        $rhtml
                    );
                } else if ($result && $result['tag'] == 'cascader') {
                    $rhtml = read_file($this->getStubTpl('search/cascader'));
                } else if ($result && $result['tag'] == 'date') {
                    $rhtml = read_file($this->getStubTpl('search/datetime'));
                } else if ($result && $result['tag'] == 'rate') {
                    $rhtml = read_file($this->getStubTpl('search/rate'));
                    $rhtml = str_replace(['{theme}', '{length}'], [$result['data_theme'], $result['data_length']], $rhtml);
                } else {
                    $rhtml = read_file($this->getStubTpl('search/input'));
                }
            }

            $replace = [
                'field'   => $searchField,
                'title'   => $this->tableFields[$searchField]['title'],
                'varlist' => ucfirst($searchField) . '_list',
                'vardata' => $varData,
            ];

            foreach ($replace as $key => $value) {
                $rhtml = str_replace("{%$key%}", $value, $rhtml);
            }

            $searchHtml[] = $rhtml;
        }

        return implode(PHP_EOL . PHP_EOL, $searchHtml);
    }

    /**
     * 修正 formDesign 中的字段名
     * 将临时字段名（如 select_2）替换为正确的数据库字段名
     * @param array $formDesign
     * @param array $tableFields
     * @return array
     */
    protected function correctFormDesignFieldNames(array $formDesign, array $tableFields): array
    {
        // 构建字段名到标题的映射
        $fieldTitleMap = [];
        foreach ($tableFields as $fieldName => $fieldInfo) {
            $title = $fieldInfo['title'] ?? '';
            if ($title) {
                $fieldTitleMap[$fieldName] = $title;
            }
        }
        
        // 递归修正字段名
        $correctItem = function($item) use (&$correctItem, $fieldTitleMap, $tableFields) {
            if (!is_array($item)) {
                return $item;
            }
            
            // 如果有 name 字段
            if (isset($item['name'])) {
                $name = $item['name'];
                // 如果 name 不在数据库字段列表中，尝试通过 label 匹配
                if (!isset($tableFields[$name])) {
                    $label = $item['label'] ?? '';
                    if ($label) {
                        // 通过标题查找对应的字段名
                        foreach ($fieldTitleMap as $fieldName => $title) {
                            if ($title === $label) {
                                $item['name'] = $fieldName;
                                break;
                            }
                        }
                    }
                }
            }
            
            // 递归处理子元素（grid 布局的子元素）
            if (isset($item['children']) && is_array($item['children'])) {
                foreach ($item['children'] as $key => $child) {
                    if (is_array($child)) {
                        // grid 布局：children 是二维数组
                        foreach ($child as $subKey => $subChild) {
                            if (is_array($subChild)) {
                                $item['children'][$key][$subKey] = $correctItem($subChild);
                            } else {
                                $item['children'][$key][$subKey] = $subChild;
                            }
                        }
                    } else {
                        $item['children'][$key] = $correctItem($child);
                    }
                }
            }
            
            return $item;
        };
        
        // 修正所有表单项
        foreach ($formDesign as $key => $item) {
            $formDesign[$key] = $correctItem($item);
        }
        
        return $formDesign;
    }

    /**
     * 获取菜单函数
     * @param array $data
     * @return array
     * @throws \Exception
     */
    protected function getMenuMethods(array $data = []): array
    {
        if (empty($data) || !is_array($data)) {
            throw new \Exception("Error Params Request", 1);
        }

        if (!is_array($data['menus'])) {
            $data['menus'] = unserialize($data['menus']);
        }

        $MenuRules = [
            'title'  => $data['title'],
            'router' => $data['controller'],
            'icon'   => $data['icon'] ?: '',
            'pid'    => $data['pid'],
            'auth'   => $data['auth'],
        ];

        foreach ($data['menus'] as $key => $value) {
            $MenuRules['children'][$key] = [
                'title'  => $value['title'],
                'router' => $value['router'],
                'auth'   => $value['auth'],
                'type'   => $value['type'],
            ];
            $parse = explode(':', $value['route']);
            $parse = end($parse);
            if (!in_array($parse, $this->filterMethod)) {
                $this->methods[$key] = $parse;
                $this->templateFiles[$key] = Str::snake($parse);
            }
        }

        return [$MenuRules, $this->methods, $this->templateFiles];
    }

    /**
     * 获取其他函数
     * @param array $methods
     * @return string
     */
    protected function getMethodString(array $methods = []): string
    {
        $outsMethod = PHP_EOL;
        foreach ($methods as $method) {
            if (!in_array($method, $this->filterMethod)) {
                $outsMethod .= str_replace('method', $method, read_file($this->getStubTpl('method')));
            }
        }
        return $outsMethod;
    }

    /**
     * 获取关联表信息
     * id style KEY
     * @param $relation
     * @return string
     * @throws \Exception
     */
    protected function getRelationMethodList($relation): string
    {
        $relationString = PHP_EOL;
        if (!empty($relation) && !is_array($relation)) {

            $relation = unserialize($relation);
            foreach ($relation as $value) {

                if (!$value) {
                    continue;
                }
                $table = str_replace($this->prefix, '', $value['table']);
                $schema = Db::query("SHOW TABLE STATUS LIKE '$table'");
                $studly = Str::studly($table);

                // 直接判断是否存在
                // 可提交遍历命名空间PR
                if (in_array($table,$this->protectTable)) {
                    $studly = '\\app\\common\\model\\system\\' . $studly;
                } else {
                    $namespace = '\\app\\admin\\model\\'.$studly;
                    if (class_exists($namespace)) {
                        $studly = $namespace;
                    }
                }

                // 拼接关联语句
                $localKey = $value['localKey'];
                $foreignKey = $value['foreignKey'];
                $str_relation = '$this->' . $value['style'] . '(' . $studly . '::Class,' . "'$foreignKey','$localKey')";

                $bindField = [];
                if ($value['relationField']) {
                    $bindField = explode(',', $value['relationField']);
                    $bindField = array_unique(array_filter($bindField));
                    $str_relation .= '->bind(' . str_replace('"', '\'', json_encode($bindField)) . ')';
                }

                try {

                    $Comment = $schema[0]['Comment'] ?? $value['table'];
                    $table = Str::camel($table);
                    $relationString .= '    /**';
                    $relationString .= PHP_EOL . '  * 定义 ' . $Comment . ' 关联模型';
                    $relationString .= PHP_EOL . '  * @localKey ' . $localKey;
                    $relationString .= PHP_EOL . '  * @bind ' . implode(',', $bindField);
                    $relationString .= PHP_EOL . '  */';
                    $relationString .= PHP_EOL . '  public function ' . $table . '()';
                    $relationString .= PHP_EOL . '  {';
                    $relationString .= PHP_EOL . '      return ' . $str_relation . ';';
                    $relationString .= PHP_EOL . '  }';
                    $relationString .= PHP_EOL;
                } catch (\Throwable $th) {
                    throw new \Exception($th->getMessage());
                }
            }
        }

        return $relationString;
    }

    /**
     * 获取文件信息
     * @param string $module
     * @param string $name
     * @param string $table
     * @param string $type
     * @return array
     * @throws \Exception
     */
    protected function parseNameData(string $module, string $name, string $table = '', string $type = 'controller'): array
    {
        $array = str_replace(['.', '/', '\\'], '/', $name);
        $array = array_filter(explode('/', $array));
        if (substr($name, 0 - strlen('/')) != '/') {
            array_pop($array);
        }

        $parseName = $type == 'controller' ? ucfirst(end($array)) : Str::studly($table);
        if (in_array(strtolower($parseName), $this->internalKeywords)) {
            throw new \Exception('类名称不能使用内置关键字' . $parseName);
        }

        array_pop($array);
        $appNamespace = "app\\{$module}\\$type" . ($array ? "\\" . implode("\\", $array) : "");
        $parseFile = root_path() . $appNamespace . DIRECTORY_SEPARATOR . $parseName . '.php';
        $parseFile = str_replace('\\', '/', $parseFile);
        return [$parseName, $appNamespace, $parseFile];
    }

    /**
     * @param $table
     * @return bool
     */
    protected function filterSystemTable($table): bool
    {
        if (in_array($table, $this->protectTable)) {
            return true;
        }
        return false;
    }

    /**
     * 获取模板文件
     * @param [type] $name
     * @return string
     */
    protected function getStubTpl($name): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . $name . '.stub';
    }

    /**
     * 获取代码模板
     * @param [type] $name
     * @return string
     */
    protected function getTemplatePath($name): string
    {
        $this->templatePath = root_path('app/admin/view');
        $array = str_replace(['.', '/', '\\'], '/', $name);
        $array = array_filter(explode('/', $array));
        if (substr($name, 0 - strlen('/')) != '/') {
            array_pop($array);
        }

        foreach ($array as $value) {
            $value = Str::snake($value);
            $this->templatePath .= $value . DIRECTORY_SEPARATOR;
        }

        return $this->templatePath;
    }
}
