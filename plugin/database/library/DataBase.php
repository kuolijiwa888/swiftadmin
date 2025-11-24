<?php declare (strict_types=1);
namespace plugin\database\library;
use support\Cache;
use think\facade\Db;
use Exception;
use think\db\exception\DbException;
class DataBase
{
    /**
     * 备份文件信息 part - 卷号，name - 文件名
     * @var array
     */
    private array $file;

    /**
     * 获取当前类文件的配置文件路径
     */
    public function getPluginConfigFileName():string
    {
        $file_path_arr=explode('\\',__CLASS__);
        return plugin_path($file_path_arr[1].'/config').end($file_path_arr).'.php';
    }

    /**
     * 获取基础配置数据
     * @param string|null $key 配置数组的下标
     * @return mixed
     * @throws Exception
     */
    public function getBaseConfig(string $key=null):mixed
    {

        if(!Cache::has('plugin_database_config')){
            Cache::set('plugin_database_config',include self::getPluginConfigFileName());
        }
        $base_config = Cache::get('plugin_database_config');
        if($key && array_key_exists($key,$base_config)){
            return $base_config[$key];
        }else if($key==null){
            return $base_config;
        }else{
            throw new Exception('指定的配置属性不存在');
        }
    }
    /**
     * 修改配置文件
     * @param array $config_info 新的配置信息
     * @return array
     */
    public function modConfig(array $config_info):array
    {
        foreach ($config_info as $key=>$value)
        {
            $value = str_replace(' ', '', $value); // 去除空格
            $value = str_replace('，', ',', $value); // 转换可能输入的中文逗号

            $config = read_file(self::getPluginConfigFileName());
            if (preg_match("'$key'", $config)) {
                if (preg_match('/^[0-9]+$/', $value)) {
                    $config = preg_replace('/(\'' . $key . '\'([\s]+)?=>([\s]+)?)[\w\'\"\s,]+,/', '${1}' . $value . ',', $config);
                } else {
                    $config = preg_replace('/(\'' . $key . '\'([\s]+)?=>([\s]+)?)[\w\'\"\s,]+,/', '${1}\'' . $value . '\',', $config);
                }
            } else {
                $config = preg_replace('/(return \[)/', "$1\r\n\r\n\t'$key' => '$value',", $config); // 自动新增配置
            }
            if(write_file(self::getPluginConfigFileName(), $config)==0)
            {
                return ['err_code'=>1,'err_msg'=>'配置文件修改写入失败'];
            }
        }
        Cache::delete('plugin_database_config');
        return ['err_code'=>0,'err_msg'=>'配置文件修改成功！'];
    }

    /**
     * 数据库表列表
     * @param null $table 为null时输出数据库中表的信息，指定表时 显示该表的字段信息
     * @param bool $type 是否显示表格全部字段信息
     * @return array
     * @throws DbException
     */
    public function TableList($table = null, bool $type=true): array
    {
        if (is_null($table)) {
            $list = Db::query("SHOW TABLE STATUS");
        } else {
            if ($type) {
                $list = Db::query("SHOW FULL COLUMNS FROM {$table}");
            }else{
                $list = Db::query("show columns from {$table}");
            }
        }
        return array_map('array_change_key_case', $list);
    }

    /**
     * 优化表
     * @param array $tables 表名
     * @return array
     * @throws DbException
     */
    public function Optimize(array $tables): array
    {
        $tables = implode('`,`', $tables);
        $list = Db::query("OPTIMIZE TABLE `{$tables}`");
        if ($list) {
            return ['err_code'=>0,'err_msg'=>'所选数据表优化成功'];
        } else {
            return ['err_code'=>1,'err_msg'=>'所选数据表优化失败，请稍后重试'];
        }
    }

    /**
     * 修复表
     * @param array $tables 表名
     * @return array
     * @throws DbException
     */
    public function Repair(array $tables): array
    {
        $tables = implode('`,`', $tables);
        $list = Db::query("REPAIR TABLE `{$tables}`");
        if ($list) {
            return ['err_code'=>0,'err_msg'=>'所选数据表修复成功'];
        } else {
            return ['err_code'=>1,'err_msg'=>'所选数据表修复失败，请稍后重试'];
        }
    }

    /**
     * 删除表
     * @param string $table
     * @return array
     */
    public function Drop(string $table):array
    {
        if(is_empty($table)){
            return ['err_code'=>1,'err_msg'=>'必须指定要删除的表名称'];
        }
        try {
            Db::query("DROP TABLE IF EXISTS `{$table}`");
        }catch (DbException $dbException){
            return ['err_code'=>1,'err_msg'=>$dbException->getMessage()];
        }
        return ['err_code'=>0,'err_msg'=>'删除数据表成功'];
    }

    /**
     * 清空表 只能单表操作
     * @param string $table
     * @return array
     */
    public function Truncate(string $table):array
    {
        if(is_empty($table)){
            return ['err_code'=>1,'err_msg'=>'必须指定要删除的表名称'];
        }
        try {
            Db::query("TRUNCATE `{$table}`");
        }catch (DbException $dbException){
            return ['err_code'=>1,'err_msg'=>$dbException->getMessage()];
        }
        return ['err_code'=>0,'err_msg'=>'清空数据表成功'];
    }
//备份操作
    /**
     * 检查目录是否可写
     * @param string $path    目录
     * @return boolean
     */
    protected function checkPath(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }
        if (mkdir($path, 0755, true)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 设置备份文件名
     * @param null $file 文件名字
     * @return object
     * @throws Exception
     */
    public function setFile($file = null): object
    {
        if (is_null($file)) {
            $this->file = ['name' => date('Ymd-His'), 'part' => 1];
        } else {
            if (!array_key_exists("name", $file) && !array_key_exists("part", $file)) {
                throw new Exception('出入的文件数组不符合要求');
            } else {
                $this->file = $file;
            }
        }
        return $this;
    }
    /**
     * 返回数组形式的file内容
     * @throws Exception
    */
    public function getFile(): array
    {
        try {
            return array(
                'pathname' => "{$this->getBaseConfig('path')}{$this->file['name']}-{$this->file['part']}.sql",
                'filename' => "{$this->file['name']}-{$this->file['part']}.sql",
                'filepath' => $this->getBaseConfig('path'),
                'file' => $this->file
            );
        } catch (Exception $e) {
            throw new Exception('获取配置参数出现错误了');
        }
    }

    /**
     * 清理备份过程中产生的缓存Cache
    */
    public function ClearCache(): bool
    {
       return Cache::deleteMultiple(['plugin_database_size','plugin_database_part','plugin_database_init','plugin_database_file_name']);
    }
    /**
     * 计算文件名称中的part
    */
    private function getPart($size=0)
    {
        //计算大小
        if(!Cache::has('plugin_database_size'))
        {
            Cache::set('plugin_database_size',0);
        }
        $size=Cache::get('plugin_database_size')+$size;
        Cache::set('plugin_database_size',$size);
        //计算分文件
        if(!Cache::has('plugin_database_part'))
        {
            Cache::set('plugin_database_part',1);
        }
        $file_part=Cache::get('plugin_database_part');

        if($size > $this->getBaseConfig('part')){
            Cache::set('plugin_database_size',0);
            $file_part =$file_part +1;
            Cache::set('plugin_database_part',$file_part);
            Cache::set('plugin_database_init',1);
        }else{
            Cache::set('plugin_database_init',0);
        }
    }

    /**
     * 获取备份文件路径+名称
     * @throws Exception
     */
    private function getBackupFileName($size=0): string
    {
        $this->getPart($size);
        if(!Cache::has('plugin_database_file_name')){
            $backup_path = public_path($this->getBaseConfig('path'));
            if( !$this->checkPath($backup_path))
            {
                throw new Exception('备份文件存储路径不可用');
            }

            $filename =$backup_path.$this->file['name'];
            Cache::set('plugin_database_file_name',$filename);
        }
        $file_part=Cache::get('plugin_database_part');
        return Cache::get('plugin_database_file_name')."-{$file_part}.sql";
    }

    /**
     * 写入SQL语句
     * @param string $sql 要写入的SQL语句
     * @return boolean     true - 写入成功，false - 写入失败！
     * @throws Exception
     */
    private function write($sql){
        $size = strlen($sql);
        //由于压缩原因，无法计算出压缩后的长度，这里假设压缩率为50%，
        //一般情况压缩率都会高于50%；
        $size = $this->getBaseConfig('compress') ? $size / 2 : $size;
        $filename=$this->getBackupFileName($size);
        if(Cache::get('plugin_database_init'))
        {
            $sql = $this->Backup_Init('in') .$sql ;
        }
        if ($this->getBaseConfig('compress')) {
            $filename = "{$filename}.gz";
            $operate = @gzopen($filename, "a{$this->getBaseConfig('level')}");
        } else {
            $operate = @fopen($filename, 'a');
        }

        $res = $this->getBaseConfig('compress') ? @gzwrite($operate, $sql) : @fwrite($operate, $sql);
        $this->getBaseConfig('compress') ? @gzclose($operate) : @fclose($operate);
        return $res>0;
    }

    /**
     * 生成备份初始化
     * @param string $type 如果等于in 则是内部调用直接返回sql内容
     * @return bool|string true - 写入成功，false - 写入失败
     * @throws Exception
     */
    public function Backup_Init(string $type=''): bool|string
    {
        $data_name= config('thinkorm.connections')[config('thinkorm.default')]['database'];
        $sql = "-- -----------------------------\n";
        $sql .= "-- Database Management SQL Dump \n";
        $sql .= "-- \n";
        $sql .= "-- 数据库名: " . $data_name . "\n";
        $sql .= "-- 生成日期: " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- PHP 版本:" . phpversion() . "\n";
        $sql .= "-- \n";
        $sql .= "-- Part : #".Cache::get('plugin_database_part',1)."\n";
        $sql .= "-- -----------------------------\n\n";
        $sql .= 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";'."\n";
        $sql .= 'SET time_zone = "+08:00";' . "\n";
        $sql .= "SET NAMES utf8mb4;\n\n";

        if($type=='in'){ return $sql; }
        return $this->write($sql);
    }

    /**
     * 备份表结构
     * @param string $table 表名
     * @param integer $start 起始行数
     * @return array|bool
     * @throws DbException
     */
    public function backupTable(string $table, int $start)
    {
        // 备份表结构
        $result = Db::query("SHOW CREATE TABLE `{$table}`");
        $sql = "\n";
        $sql .= "-- -----------------------------\n";
        $sql .= "-- Table structure for `{$table}`\n";
        $sql .= "-- -----------------------------\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= trim($result[0]['Create Table']) . ";\n\n";

        if (false === $this->write($sql)) {
            return false;
        }
        //数据总数
        $result = Db::query("SELECT COUNT(*) AS count FROM `{$table}`");
        $count = $result['0']['count'];
        //备份表数据
        if ($count) {
            $fields = self::get_table_field($table);  //获取表格字段名称数组

            if(!self::create_table_content($table,$fields,$start,$count))
            {
                return false;
            }

        }
        return true;
    }

    /**
     * 获取表格字段
     * @param string $table 表名
     * @return array
     * @throws DbException
     */
    private function get_table_field(string $table): array
    {
        $field_data = Db::query("show columns FROM " . $table); // 获取表格字段数组
        return array_column($field_data, 'Field');
    }

    /**
     * 生成表格转存sql
     * @param string $table 正在备份的表名
     * @param array $fields 表格字段名称数组
     * @param int $start 从第几行开始
     * @param int $count 数据量 行数
     * @return bool
     * @throws DbException
     */
    private function create_table_content(string $table,array $fields,int $start,int $count): bool
    {
        //写入数据注释
        if (0 == $start) {
            $sql = "-- -----------------------------\n";
            $sql .= "-- Records of `{$table}`\n";
            $sql .= "-- -----------------------------\n";
            $this->write($sql);
        }
        //备份数据记录
        $insert_sql = "INSERT INTO `" . $table . "` (`" . implode('`,`', $fields) . "`) VALUES" . PHP_EOL;

        //进行只读锁定 放置备份的同时写入数据
        Db::query("LOCK TABLES `{$table}` READ ");

        $result = Db::query("SELECT * FROM `{$table}` LIMIT {$start}, 100");
        $last_key=array_key_last($result);
        $sql='';
        foreach ($result as $key=> $row){
            $values = [];
            foreach ($row as $val) {
                if (is_int($val)) {
                    $values[] = $val;
                } else if ($val==null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = "'".str_replace(array("\r", "\n"), array('\\r', '\\n'), addslashes($val))."'";
                }
            }
            $end_string=$last_key==$key?';':',';
            $sql .= "(" . implode(", ", $values) . "){$end_string}\n";
        }
        if(false === $this->write($insert_sql.$sql)){
            return false;
        }
        //还有更多数据
        if ($count > $start + 100) {
            return $this->create_table_content($table, $fields,$start + 100,$count);
        }else{
            Db::query("UNLOCK TABLES"); //解锁
            return true;
        }
    }

    /**
     * 数据库备份文件列表
    */
    public function get_backup_file_list()
    {
        if(!is_dir( public_path($this->getBaseConfig('path')))){
            mkdir(public_path($this->getBaseConfig('path')), 0755, true);
        }
        $path = realpath(public_path($this->getBaseConfig('path')));
        $glob = new \FilesystemIterator($path,  \FilesystemIterator::KEY_AS_FILENAME);

        $list = array();
        foreach ($glob as $name => $file) {

            if(preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql(?:\.gz)?$/', $name)){
                $name = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');

                $date = "{$name[0]}{$name[1]}{$name[2]}";
                $time = "{$name[3]}{$name[4]}{$name[5]}";
                $part = $name[6];

                if(isset($list["{$date}{$time}"])){
                    $info = $list["{$date}{$time}"];
                    $info['part'] = max($info['part'], $part);
                    $info['size'] = $info['size'] + $file->getSize();
                } else {
                    $info['part'] = $part;
                    $info['size'] = $file->getSize();
                }
                $extension        = strtoupper(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                $info['filename'][$info['part']]= $file->getFilename();
                $list["{$date}{$time}"] = $info;

            }
            $list["{$date}{$time}"]['back_name'] ="{$date}-{$time}";
            $list["{$date}{$time}"]['extension'] =$extension;
            $list["{$date}{$time}"]['cTime'] =date('Y-m-d H:i:s',$file->getcTime());
            $list["{$date}{$time}"]['extension'] =$extension;
        }
       return is_null($list)?['err_code'=>1,'err_msg'=>'不存在备份文件']:$list;
    }

    /**
     * 处理备份文件
     *
     * @throws Exception
    */
    public function down_backup_file($back_name)
    {
        $path=public_path($this->getBaseConfig('path')).$back_name.'-*.sql*';
        $file_arr=glob($path);
        if(!is_dir(public_path('downfile'))){
            mk_dirs(public_path('downfile'));
        }
        $zip_name= public_path('downfile/'.$back_name).'.zip';
        if(file_exists($zip_name)){
            unlink($zip_name);
        }
        try {
            $zip=new \ZipArchive();
            $zip->open($zip_name,\ZipArchive::CREATE);
            foreach ($file_arr as $file)
            {
                $zip->addFile($file,str_replace(public_path('backup/database'),'',$file));
            }
            $zip->close();
        }catch (Exception $e)
        {
            return ['err_code'=>1,'err_msg'=>$e->getMessage()];
        }
        return ['err_code'=>0,'file_path'=>$zip_name];
    }

    /**
     * 还原备份文件
    */
    public function backup_file_import($back_name)
    {
        $path=public_path($this->getBaseConfig('path')).$back_name.'-*.sql*';
        $file_arr=glob($path);

        foreach ($file_arr as $item)
        {
            $sql  = '';
            if(pathinfo($item, PATHINFO_EXTENSION)=='sql')
            {
                $gz   = fopen($item, 'r');
                while (!feof($gz)) {
                    $sql .=  fgets($gz);
                    if(preg_match('/.*;$/', trim($sql))){
                        if(false === Db::execute($sql)){
                            return false;
                        }
                        $sql = '';
                    }
                }
                fclose($gz);
            }else{
                $gz   = gzopen($item, 'r');
                while (!gzeof($gz)) {
                    $sql .= gzgets($gz);
                    if(preg_match('/.*;$/', trim($sql))){
                        if(false === Db::execute($sql)){
                            return false;
                        }
                        $sql = '';
                    }
                }
                gzclose($gz);
            }

        }
        return true;
    }
}