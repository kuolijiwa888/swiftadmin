<?php
declare(strict_types=1);

namespace app\admin\controller\developer;

use app\AdminController;
use app\common\model\system\AdminRules;
use Exception;
use FilesystemIterator;
use Psr\SimpleCache\InvalidArgumentException;
use support\Response;
use system\File;
use system\ZipArchives;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use app\admin\controller\system\Plugin as PluginService;

/**
 * 插件开发
 * <!--Developer-->
 * Class Plugin
 * @package app\admin\controller\developer
 */
class Plugin extends AdminController
{

    // 初始化操作
    public function __construct()
    {
        parent::__construct();
        $this->model = new \app\admin\model\developer\Plugin();
    }

    /**
     * 获取资源
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index(): \support\Response
    {
        if (request()->isAjax()) {

            $param = \request()->post();
            $param['page'] = (int)input('page');
            $param['limit'] = (int)('limit');
            $count = $this->model->count();
            $limit = empty($param['limit']) ? 10 : $param['limit'];
            $page = ($count <= $limit) ? 1 : $param['page'];
            $list = $this->model->order("id desc")->limit($limit)->page($page)->select()->toArray();
            return $this->success('查询成功', null, $list, $count);
        }

        return view('/developer/plugin/index');
    }

    /**
     * 上传插件
     * @return array
     * @throws Exception
     */
    public function parseFile(): array
    {
        try {

            $file = \request()->file('file');
            if (empty($file)) {
                throw new \Exception('插件上传失败');
            }

            $uploadName = $file->getUploadName();
            $filePath = plugin_path() . $uploadName;
            if (!$file->move($filePath)) {
                throw new \Exception('插件上传失败');
            }

            $uploadName = pathinfo($uploadName)['filename'];
            $uploadName = explode('-', $uploadName);
            $fileName = current($uploadName);

        } catch (\Throwable $th) {
            throw new \Exception($th->getMessage());
        }

        return [strtolower($fileName), $filePath];
    }

    /**
     * 执行本地安装测试
     * @return Response
     * @throws InvalidArgumentException
     */
    public function install(): Response
    {
        try {
            list($name, $filePath) = $this->parseFile();
            if (is_dir(plugin_path($name))) {
                throw new \Exception('插件已存在');
            }
            ZipArchives::unzip($filePath, plugin_path(), '', true);
            $pluginClass = get_plugin_instance($name);
            $pluginClass->install();
            PluginService::pluginMenu($name);
            PluginService::executeSql($name);
            PluginService::enabled($name);

        } catch (\Throwable $th) {
            return $this->error($th->getMessage());
        }

        return $this->success('插件安装成功');
    }

    /**
     * 升级插件
     * @return \support\Response
     * @throws Exception|\Psr\SimpleCache\InvalidArgumentException
     */
    public function upgrade(): \support\Response
    {
        try {

            list($name, $filePath) = $this->parseFile();
            $pluginPath = plugin_path($name);
            $pluginInfo = get_plugin_config($name, true);
            if (!$pluginInfo) {
                throw new \Exception('插件不存在');
            }

            if ($pluginInfo['status']) {
                throw new \Exception('插件已启用，请先禁用插件');
            }

            $formIndex = ZipArchives::unzip($filePath, plugin_path(), 'config.json');
            $upgradeInfo = json_decode($formIndex, true);
            if (version_compare($upgradeInfo['version'], $pluginInfo['version'], "<=")) {
                throw new \Exception('升级版本不能低于已安装版本');
            }

            $backupDir = root_path() . $name . '_' . $pluginInfo['version'] . '.zip';
            ZipArchives::compression($backupDir, $pluginPath, plugin_path());
            ZipArchives::unzip($filePath, plugin_path(), '', true);

            $pluginClass = get_plugin_instance($name, 'upgrade');
            $pluginClass->execute($pluginInfo['version'], $upgradeInfo['version']);
            $data = array_merge($upgradeInfo, [
                'extends' => $pluginInfo['extends'],
                'rewrite' => $pluginInfo['rewrite'],
            ]);

            write_file($pluginPath . 'config.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            PluginService::pluginMenu($name);
            PluginService::executeSql($name);
            PluginService::enabled($name);

        } catch (\Exception $th) {
            return $this->error($th->getMessage());
        }

        return $this->success('插件升级成功', null, $pluginInfo);
    }

    /**
     * 导入本地插件
     * @return Response
     * @throws DataNotFoundException
     * @throws DbException
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     */
    public function import(): \support\Response
    {
        if (request()->isPost()) {

            $name = input('dir');
            $pluginInfo = get_plugin_config($name, true);
            if (empty($pluginInfo)) {
                return $this->error('插件信息获取失败');
            }

            // 是否已经导入
            $result = $this->model->where('name', $name)->findOrEmpty();
            if (!$result->isEmpty()) {
                return $this->error('请勿重复导入插件');
            }

            try {
                $pluginInfo['import'] = time();
                $this->model->create($pluginInfo);
            } catch (\Throwable $th) {
                return $this->error($th->getMessage());
            }

            return $this->success('导入成功');
        }

        $dirs = [];
        $iterator = new FilesystemIterator(plugin_path(), FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                // 是否已经导入
                $find = $this->model->where('name', $item->getBasename())->find();
                if (empty($find)) {
                    $name = $item->getBasename();
                    $info = get_plugin_config($name);
                    $dirs[] = [
                        'name'  => $name,
                        'title' => $info['title'] ?? '',
                    ];
                }
            }
        }

        return view('/developer/plugin/import', ['dirs' => $dirs]);
    }

    /**
     * 插件生成
     * @return \support\Response
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function build(): \support\Response
    {
        $id = input('id');
        $data = $this->model->where('id', $id)->withoutField('id,status,create_time')->find();
        if (empty($data)) {
            return $this->error('插件不存在');
        }

        $name = $data['name'];
        $pluginPath = plugin_path($name);
        $pluginClass = ucfirst($name);
        if ($data['import'] || is_dir($pluginPath)) {
            return $this->error('请勿重复生成插件');
        }

        $pluginZip = __DIR__ . '/stubs/plugin_init.stub';
        $sourceFiles = plugin_path('plugin_init');
        $replaces = ['controller', 'model', 'validate', 'view'];
        $source = ['{name}', '{pluginClass}', '{title}', '{icon}'];
        $destVars = [$name, $pluginClass, $data['title'], $data['icon']];

        try {

            ZipArchives::unzip($pluginZip, plugin_path());
            foreach ($replaces as $item) {
                $namespace = $sourceFiles . 'app/admin' . DIRECTORY_SEPARATOR . $item;
                $tempPath = $namespace . DIRECTORY_SEPARATOR . 'demo';
                $tempPath = str_replace('\\', '/', $tempPath);
                if (is_dir($tempPath)) {
                    $isFirst = false;
                    $targetPath = $namespace . DIRECTORY_SEPARATOR . $name;
                    rename($tempPath, $targetPath);
                    $targetFile = $targetPath . DIRECTORY_SEPARATOR . 'Index.php';
                    if (!is_file($targetFile)) {
                        $isFirst = true;
                        $targetFile = $targetPath . DIRECTORY_SEPARATOR . ($item === 'view' ? '/index/index.html' : 'Demo.php');
                    }
                    $content = str_replace($source, $destVars, read_file($targetFile));
                    if (!empty($content)) {
                        write_file($targetFile, $content);
                    }
                    if ($isFirst && $content && $item !== 'view') {
                        rename($targetFile, $targetPath . DIRECTORY_SEPARATOR . $pluginClass . '.php');
                    }
                }
            }

            $array = [$sourceFiles . 'app/index/controller/', $sourceFiles];
            foreach ($array as $item) {
                $file = $item . 'Demo.php';
                $content = str_replace($source, $destVars, read_file($file));
                write_file($file, $content);
                rename($file, $item . $pluginClass . '.php');
            }

            foreach (['README.md', 'function.php', 'Upgrade.php', 'config.html'] as $item) {
                $file = $sourceFiles . $item;
                $content = str_replace($source, $destVars, read_file($file));
                write_file($file, $content);
            }

            // 处理静态文件
            $staticFile = [
                $sourceFiles . 'app/index/view',
                $sourceFiles . 'public/static/plugin',
                $sourceFiles . 'public/static/system/plugin'
            ];

            foreach ($staticFile as $index => $item) {
                $file = $item . '/demo';
                if (!$index) {
                    $elem = $file . DIRECTORY_SEPARATOR . 'index.html';
                    write_file($elem, str_replace('{pluginClass}', $pluginClass, read_file($elem)));
                }
                rename($item . '/' . 'demo', $item . '/' . $name);
            }

            $configPath = $sourceFiles . 'config.json';
            $data = $data->toArray();
            $data['rewrite'] = [];
            $data['extends'] = [
                'title' => $data['title'],
            ];
            $data['area'] = ['600px', '650px',];
            write_file($configPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            rename($sourceFiles, $pluginPath);

            if ($data['menu']) {
                AdminRules::createMenu($this->createPluginMenu($data), $name);
            }

            // 启用插件
            foreach (File::getCopyDirs($name) as $copyDir) {
                copydirs($copyDir, root_path() . str_replace($pluginPath, '', $copyDir));
            }

            AdminRules::enabled($name);
            set_plugin_config($name, ['status' => 1]);
        } catch (\Throwable $th) {
            @recursive_delete($pluginPath);
            @recursive_delete($sourceFiles);
            return $this->error($th->getMessage());
        }

        return $this->success('插件初始化成功');
    }

    /**
     * 插件打包
     * @return \support\Response
     * @throws Exception
     */
    public function package(): \support\Response
    {
        $id = input('id');
        $data = $this->model->withoutField('id, import, create_time')->where('id', $id)->find();
        if (empty($data)) {
            return $this->error('插件不存在，打包失败!');
        }

        $name = $data['name'];
        $pluginClass = ucfirst($name);
        $pluginPath = plugin_path($name);
        if (!is_dir($pluginPath)) {
            return $this->error('插件未生成!');
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(root_path('app'), FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    $content = read_file($filePath);
                    if (preg_match('/<!--' . $pluginClass . '-->/iU', $content, $match)) {
                        $match = $match[0];
                        $filePath = str_replace('\\', '/', $filePath);
                        copy_file($filePath, str_replace(root_path(), $pluginPath, $filePath));
                    }
                }
            }

            $statics = [
                root_path('public/static/plugin/' . $name),
                root_path('public/static/system/plugin/' . $name),
            ];

            foreach ($statics as $item) {
                if (is_dir($item)) {
                    @copydirs($item, str_replace(root_path(), $pluginPath, $item));
                }
            }

            if (!empty($data['menu'])) {
                arr2file($pluginPath . '/data/menu.php', AdminRules::export($name));
            }

            // 加载配置文件
            $configPath = $pluginPath . 'config.json';
            $configInfo = json_decode(read_file($configPath), true);
            $configPack = array_merge($configInfo, $data->toArray());
            unset($configPack['path']);
            unset($configPack['filePath']);
            if (empty($configPack['rewrite'])) {
                $configPack['rewrite'] = [];
            }
            write_file($configPath, json_encode($configPack, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $pluginZip = root_path('public/upload') . $name . '-' . $data['version'] . '.zip';
            ZipArchives::compression($pluginZip, $pluginPath);
            $url = request()->domain() . str_replace(root_path() . 'public', '', $pluginZip);

        } catch (\Throwable $th) {
            return $this->error($th->getMessage());
        }

        return $this->success('插件打包成功', $url);
    }

    /**
     * 基础菜单项
     * @param $plugin
     * @return array
     */
    public function createPluginMenu($plugin): array
    {
        return [
            [
                'title'    => $plugin['title'],
                'router'   => '/' . $plugin['name'] . '/Index',
                'icon'     => $plugin['icon'],
                'auth'     => '1',
                'children' => [
                    ['router' => '/' . $plugin['name'] . '/Index/index', 'title' => '查看'],
                    ['router' => '/' . $plugin['name'] . '/Index/add', 'title' => '添加'],
                    ['router' => '/' . $plugin['name'] . '/Index/edit', 'title' => '编辑'],
                    ['router' => '/' . $plugin['name'] . '/Index/del', 'title' => '删除'],
                    ['router' => '/' . $plugin['name'] . '/Index/status', 'title' => '状态']
                ]
            ],
        ];
    }
}
