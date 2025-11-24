<?php declare(strict_types = 1);

namespace app\admin\controller\database;
use app\AdminController;
use support\Response;
use support\Cache;
use plugin\database\library\DataBase;
use think\db\exception\DbException;

/**
 * 数据库管理后台控制器
 * <!--Database-->
 */
class Index extends AdminController {
    private DataBase $dataService;
    // 初始化函数
    public function __construct()
    {
        parent::__construct();
        $this->dataService=new DataBase();
    }

    /**
     * 初始化后台首页
     * @return Response
     * @throws DbException
     */
    public function index(): Response
    {
        if(request()->isAjax())
        {
            $list = $this->dataService->TableList();
            if(is_array($list) && count($list)>3)
            {
                return $this->success('数据库数据表列表','',$list);
            }
            return $this->error('数据获取错误');
        }
        return $this->view();
    }

    /**
     * 备份基础设置
    */
    public function setConfig():Response
    {
        if(request()->isAjax())
        {
           $res = $this->dataService->modConfig(request()->post());
            if($res['err_code']==0)
            {
                return $this->success('配置保存成功');
            }
            return $this->error($res['err_msg']);
        }

        return $this->view('',$this->dataService->getBaseConfig());
    }
    /**
     * 备份时不备份的表设置
    */
    public function ignoreTable(): Response
    {
        $list =[];
        foreach ($this->dataService->TableList() as $item)
        {
            $list[]=["value"=> $item['name'], "title"=>$item['name']];
        }

        return $this->view('',['table_list'=>json_encode($list)]);
    }
    /**
     * 优化表
    */
    public function batch_table(): Response
    {
        $action=strtolower(request()->get('action',null));
        if($action==null || !in_array($action,['optimize','repair','drop','truncate'])){
            return $this->error('请求参数错误，未传递action');
        }
        $tab_arr=request()->post('table_names',null);
        switch ($action)
        {
            case 'optimize';
            case 'repair';
                if($tab_arr==null)
                {
                    $tab_arr=array_column($this->dataService->TableList(),'name');
                }
                break;
            default;
                if($tab_arr==null)
                {
                    return $this->error('无法处理当前对于全部数据的请求，删除数据表或是清空数据表，必须指定表名');
                }
                break;
        }

        $res =match ($action){
            'optimize' =>$this->dataService->Optimize($tab_arr),
            'repair'   =>$this->dataService->Repair($tab_arr),
            'drop'     =>self::danger_batch('drop',$tab_arr),
            'truncate' =>self::danger_batch('truncate',$tab_arr)
        };

        if($res['err_code']==0)
        {
            return $this->success($res['err_msg']);
        }
        return $this->error($res['err_msg']);
    }

    private function danger_batch($action,$tables): array
    {
        foreach ($tables as $table)
        {
            $res =match ($action){
                'drop'     =>$this->dataService->Drop($table),
                'truncate' =>$this->dataService->Truncate($table)
            };
            if($res['err_code']>0)
            {
                return $res;
            }
        }
        return ['err_code'=>0,'err_msg'=>'操作成功'];
    }

    /**
     * 备份初始化
    */
    public function backupInit(): Response
    {
        if(Cache::has('plugin_database_file_name')){
            return $this->error('检测到上次备份尚未完成,确认当前无正在备份操作，您可以清理系统缓存后重试');
        }
        Cache::set('plugin_database_file_arr',$this->dataService->setFile()->getFile());
        $res=$this->dataService->setFile(Cache::get('plugin_database_file_arr')["file"])->Backup_Init();
        if(false !== $res)
        {
           return $this->success('备份初始化成功','',['table_index'=>0,'start' => 0]);
        }else{
            return $this->error('初始化失败，备份文件创建失败！');
        }
    }
    /**
     * 备份表数据
    */
    public function backupTables(): Response
    {
        if(request()->isAjax())
        {
            $table_name = request()->post('table_name');
            $start=(int) request()->post('start');
            $tab_index=(int) request()->post('tab_index');
            $res=$this->dataService->setFile(Cache::get('plugin_database_file_arr')["file"])->backupTable($table_name,$start);
            return $this->success($table_name.'备份成功','',['table_index'=>++$tab_index,'start' => 0]);
        }

        $whole=(int)request()->get('whole',0);
        $ignore_tab = explode(',',$this->dataService->getBaseConfig('ignore'));
        $full_tab = array_column($this->dataService->TableList(),'name');
        $tables=$whole?array_values(array_diff($full_tab,$ignore_tab)):'';
        return $this->view('',['whole'=>$whole,'tables'=>json_encode($tables)]);
    }

    /**
     * 清理备份中产生的临时缓存
    */
    public function ClearCache_key(): Response
    {
        Cache::delete('plugin_database_file_arr');
        if($this->dataService->ClearCache())
        {
            return $this->success('备份完成');
        }
        return $this->error('当前备份完成，但是清理备份过程中缓存出现错误，您可以忽略然后用系统自带清理缓存功能');
    }
    /**
     * 管理备份文件
    */
    public function restore(): Response
    {

        if(request()->isAjax())
        {
            $file_list = $this->dataService->get_backup_file_list();
            krsort($file_list);
            return $this->success('备份文件','',$file_list);
        }
        return $this->view();
    }
    /**
     * 删除备份文件
    */
    public function del_backup_file()
    {
        $file_arr=request()->post('file_arr');
        foreach ($file_arr as $item)
        {
            $file_path=public_path($this->dataService->getBaseConfig('path')).$item;
            if(unlink($file_path)==false)
            {
                return $this->error('删除文件失败，请刷新后检查备份文件是否存在');
            }
        }
        return $this->success('备份文件已经被移除');
    }
    /**
     * 下载备份文件（多个文件合并）
    */
    public function down_backup_file()
    {
        $back_name=request()->post('back_name');
        $res= $this->dataService->down_backup_file($back_name);
        if($res['err_code']==1)
        {
            return $this->error('备份文件处理失败'.$res['err_msg']);
        }
        $file_link=str_replace(public_path(),'',$res['file_path']);

        return $this->success('即将弹出下载保存窗口',$file_link);
    }

    /**
     * 还原备份文件到数据库
     * 仅实现备份文件还原，外部文件导入，需要解压出来再进行操作
    */
    public function restoreDatabase()
    {
        $back_name=request()->post('back_name');
        if($this->dataService->backup_file_import($back_name))
        {
            return $this->success('还原备份文件已经成功导入数据库！');
        }
        return $this->error('数据库文件执行失败，请检查文件代码！');
    }
}
