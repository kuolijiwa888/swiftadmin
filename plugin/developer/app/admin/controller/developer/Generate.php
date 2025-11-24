<?php
declare(strict_types=1);

namespace app\admin\controller\developer;

use app\AdminController;
use support\Response;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;

/**
 * 代码生成器
 * <!--Developer-->
 * Class Generate
 * @package app\admin\controller\developer
 */
class Generate extends AdminController
{
    /**
     * 数据表前缀
     *
     * @var mixed
     */
    public mixed $prefix = 'sa_';

    /**
     * 插件列表
     * @var mixed
     */
    public mixed $pluginList = [];

    /**
     * 过滤字段
     * @var array
     */
    public array $filterField = ['id', 'update_time', 'create_time', 'delete_time'];

    // 初始化操作
    public function __construct()
    {
        parent::__construct();
        $this->model = new \app\admin\model\developer\Generate();
        $this->prefix = function_exists('get_env') ? get_env('DATABASE_PREFIX') : getenv('DATABASE_PREFIX');
        $this->pluginList = \app\admin\model\developer\Plugin::select();
    }

    /**
     * 显示列表
     *
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index(): Response
    {
        if (request()->isAjax()) {

            $param = \request()->all();
            $param['page'] = (int)input('page');
            $param['limit'] = (int)input('limit');
            // 查询条件
            $where = array();
            if (!empty($param['title'])) {
                $where[] = ['title', 'like', '%' . $param['title'] . '%'];
            }

            // 查询数据
            $count = $this->model->where($where)->count();
            $limit = is_empty($param['limit']) ? 10 : $param['limit'];
            $page = ($count <= $limit) ? 1 : $param['page'];
            $list = $this->model->where($where)->order("id desc")->limit($limit)->page($page)->select()->toArray();
            return $this->success('查询成功', null, $list, $count);
        }

        return view('/developer/generate/index');
    }

    /**
     * 添加生成数据
     *
     * @return \support\Response
     */
    public function add(): \support\Response
    {
        if (request()->isPost()) {

            $post = $this->insert_before(\request()->post());

            if ($this->model->create($post)) {
                return $this->success();
            }

            return $this->error();
        }

        return view('/developer/generate/add', [
            'data'       => $this->getTableFields(),
            'tables'     => Db::getTables(),
            'pluginList' => $this->pluginList,
        ]);
    }

    /**
     * 编辑生成数据
     * @return \support\Response
     * @throws \Exception
     */
    public function edit(): \support\Response
    {

        if (request()->isPost()) {

            $post = $this->insert_before(\request()->post());
            $post = request_validate_rules($post, get_class($this->model));
            if (empty($post) || !is_array($post)) {
                return $this->error($post);
            }
            $variable = ['force', 'create', 'auth', 'global', 'delete'];
            foreach ($variable as $value) {
                if (!isset($post[$value])) {
                    $post[$value] = 0;
                }
            }

            if ($this->model->update($post)) {
                return $this->success();
            }

            return $this->error();
        }

        $id = input('id');
        $data = $this->model->find($id);
        if (!$data) {
            return $this->error('not found');
        }

        // 查询当前表
        $table = str_replace($this->prefix, '', $data['table']);
        $data['localFields'] = $this->queryFields($table);
        $data['listField'] = $data['listField'] ? json_encode(explode(',', $data['listField'])) : json_encode([]);

        if ($data['relation']) {
            $data['relation'] = unserialize($data['relation']);
        }
        if ($data['menus']) {
            $data['menus'] = unserialize($data['menus']);
        }

        // 渲染模板
        return view('/developer/generate/edit', [
            'data'       => $data,
            'tables'     => Db::getTables(),
            'pluginList' => $this->pluginList,
        ]);
    }

    /**
     * 数据预处理
     *
     * @param array $post
     * @return array
     */
    public function insert_before(array $post = []): array
    {
        // 是否存在关联表
        if (isset($post['relation_table'])) {

            foreach ($post['relation_table'] as $key => $value) {
                if (empty($value)) {
                    continue;
                }
                $post['relation'][$key]['table'] = $value;
                $post['relation'][$key]['style'] = $post['relation_style'][$key];
                $post['relation'][$key]['foreignKey'] = $post['foreignKey'][$key];
                $post['relation'][$key]['localKey'] = $post['localKey'][$key];
                $post['relation'][$key]['relationField'] = $post['relationField'][$key];
            }

            if (!empty($post['relation'])) {
                $post['relation'] = serialize($post['relation']);
            }
        } else {
            $post['relation'] = '';
        }

        // 处理菜单项
        $menuParams = [];
        foreach ($post['menus']['title'] as $key => $value) {
            $menuParams[$key]['title'] = $value;
            $menuParams[$key]['route'] = $post['menus']['route'][$key];
            $menuParams[$key]['router'] = $post['menus']['router'][$key];
            $menuParams[$key]['template'] = $post['menus']['template'][$key];
            $menuParams[$key]['auth'] = $post['menus']['auth'][$key];
            $menuParams[$key]['type'] = $post['menus']['type'][$key];
        }

        $post['menus'] = $menuParams ? serialize($menuParams) : '';
        return $post ?: [];
    }

    /**
     * 表单设计器
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function formDesign(): \support\Response
    {
        $id = input('id');

        if (request()->isPost()) {
            $post['id'] = $id;
            $post['formName'] = input('formName');
            $post['width'] = input('width');
            $post['height'] = input('height');
            $post['formType'] = input('formType');
            $post['formDesign'] = input('formDesign');
            if ($this->model->update($post)) {
                return $this->success();
            }
            return $this->error();
        }

        $data = $this->model->find($id);
        if (!$data) {
            return $this->error('not found');
        }

        $tableInfo = [];
        $table = str_replace($this->prefix, '', $data['table']);
        $tableFields = Db::name($table)->getFields();
        foreach ($tableFields as $key => $value) {
            if (!in_array($key, $this->filterField)) {
                $info = explode(';', $value['comment']);
                if ($info) {
                    $tableInfo[$key] = current($info);
                }
            }
        }

        return view('/developer/generate/form_design', [
            'data'  => $data,
            'table' => json_encode($tableInfo, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * 查询表字段
     * @param string $name
     * @return false|string
     */
    public function queryFields(string $name = '')
    {
        $list = [];
        $table = input('table');
        if (empty($table)) {
            $table = $name;
        }

        $field = Db::name($table)->getTableFields();
        var_dump($field);
        foreach ($field as $key => $value) {
            $list[$key]['value'] = $value;
            $list[$key]['name'] = $value;
        }

        return json_encode($list ?: []);
    }
}
