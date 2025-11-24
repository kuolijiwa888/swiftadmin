<?php
declare(strict_types=1);

namespace app\command;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * API 文档生成命令
 * Class ApiDoc
 * @package app\command
 */
class ApiDoc extends Command
{
    protected static $defaultName = 'api:doc';
    protected static $defaultDescription = '生成 API 接口文档';

    /**
     * 配置命令参数
     */
    protected function configure()
    {
        $this->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'API 基础 URL', 'http://localhost')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, '输出文件名', 'api.html')
            ->addOption('title', 't', InputOption::VALUE_OPTIONAL, '文档标题', 'SwiftAdmin API 文档')
            ->addOption('author', 'a', InputOption::VALUE_OPTIONAL, '文档作者', 'SwiftAdmin')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '强制覆盖已存在的文件');
    }

    /**
     * 执行命令
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = $input->getOption('url');
        $outputFile = $input->getOption('output');
        $title = $input->getOption('title');
        $author = $input->getOption('author');
        $force = $input->getOption('force');

        $output->writeln('<info>开始生成 API 文档...</info>');

        // 扫描 API 控制器
        $controllers = $this->scanControllers();
        if (empty($controllers)) {
            $output->writeln('<error>未找到 API 控制器</error>');
            return Command::FAILURE;
        }

        // 解析控制器和方法
        $apiData = $this->parseControllers($controllers, $url);

        // 生成 HTML 文档
        $html = $this->generateHtml($apiData, $title, $author, $url);

        // 输出文件
        $outputPath = base_path() . '/public/' . $outputFile;
        if (file_exists($outputPath) && !$force) {
            $output->writeln("<error>文件 {$outputFile} 已存在，使用 --force 参数强制覆盖</error>");
            return Command::FAILURE;
        }

        file_put_contents($outputPath, $html);
        $output->writeln("<info>API 文档生成成功: {$outputPath}</info>");
        $output->writeln("<info>共生成 " . count($apiData) . " 个控制器的文档</info>");

        return Command::SUCCESS;
    }

    /**
     * 扫描 API 控制器
     */
    protected function scanControllers(): array
    {
        $controllerPath = base_path() . '/app/api/controller';
        if (!is_dir($controllerPath)) {
            return [];
        }

        $controllers = [];
        $files = glob($controllerPath . '/*.php');

        foreach ($files as $file) {
            $className = 'app\\api\\controller\\' . basename($file, '.php');
            if (class_exists($className)) {
                $controllers[] = $className;
            }
        }

        return $controllers;
    }

    /**
     * 解析控制器
     */
    protected function parseControllers(array $controllers, string $baseUrl): array
    {
        $apiData = [];

        foreach ($controllers as $controllerClass) {
            $reflection = new ReflectionClass($controllerClass);
            
            // 跳过抽象类和接口
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            // 获取控制器注释
            $controllerDocComment = $reflection->getDocComment();
            $controllerDocComment = $controllerDocComment === false ? null : $controllerDocComment;
            $controllerDoc = $this->parseDocComment($controllerDocComment);
            $controllerName = $reflection->getShortName();
            $controllerTitle = $controllerDoc['title'] ?? $controllerName;

            // 获取控制器路径
            $controllerPath = strtolower($controllerName);

            // 获取控制器的登录配置
            $needLogin = true; // 默认需要登录
            $noNeedLogin = [];
            
            try {
                if ($reflection->hasProperty('needLogin')) {
                    $needLoginProp = $reflection->getProperty('needLogin');
                    $needLoginProp->setAccessible(true);
                    $needLogin = $needLoginProp->getValue($reflection->newInstanceWithoutConstructor()) ?? true;
                }
                
                if ($reflection->hasProperty('noNeedLogin')) {
                    $noNeedLoginProp = $reflection->getProperty('noNeedLogin');
                    $noNeedLoginProp->setAccessible(true);
                    $noNeedLogin = $noNeedLoginProp->getValue($reflection->newInstanceWithoutConstructor()) ?? [];
                }
            } catch (\Exception $e) {
                // 如果获取失败，使用默认值
            }

            $methods = [];
            $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                // 跳过魔术方法和构造函数
                if ($method->isConstructor() || 
                    strpos($method->getName(), '__') === 0) {
                    continue;
                }
                
                // 只处理当前控制器定义的方法，跳过继承的方法
                if ($method->getDeclaringClass()->getName() !== $controllerClass) {
                    continue;
                }
                
                // 跳过基类中的通用方法
                $skipMethods = ['success', 'error', 'logOut'];
                if (in_array($method->getName(), $skipMethods)) {
                    continue;
                }

                $methodName = $method->getName();
                // 判断该方法是否需要登录
                $methodNeedLogin = $needLogin && !in_array($methodName, $noNeedLogin);
                
                $methodDoc = $this->parseMethodDoc($method, $controllerPath, $baseUrl, $methodNeedLogin);
                
                if ($methodDoc) {
                    $methods[] = $methodDoc;
                }
            }

            if (!empty($methods)) {
                $apiData[] = [
                    'controller' => $controllerName,
                    'title' => $controllerTitle,
                    'description' => $controllerDoc['description'] ?? '',
                    'methods' => $methods
                ];
            }
        }

        return $apiData;
    }

    /**
     * 解析方法文档
     */
    protected function parseMethodDoc(ReflectionMethod $method, string $controllerPath, string $baseUrl, bool $needLogin = true): ?array
    {
        $methodName = $method->getName();
        $docComment = $method->getDocComment();
        $docComment = $docComment === false ? null : $docComment;
        $doc = $this->parseDocComment($docComment);

        // 获取参数信息
        $params = [];
        
        // 优先从注释中提取所有 @param 参数（不包括 Request 类型）
        $commentParams = $this->parseCommentParams($docComment);
        if (!empty($commentParams)) {
            // 如果注释中有参数说明，使用注释中的参数
            $params = $commentParams;
        } else {
            // 如果注释中没有参数，解析方法参数和代码
            $parameters = $method->getParameters();
            
            // 检查是否有 Request 类型的参数
            $hasRequestParam = false;
            foreach ($parameters as $param) {
                $paramType = 'mixed';
                if ($param->getType()) {
                    $type = $param->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $paramType = $type->getName();
                    } elseif (method_exists($type, 'getName')) {
                        $paramType = $type->getName();
                    }
                }
                
                // 如果是 Request 类型，跳过，改为解析方法体中的实际参数
                if ($paramType === 'support\Request' || $paramType === 'Request' || strpos($paramType, 'Request') !== false) {
                    $hasRequestParam = true;
                    continue;
                }
                
                // 非 Request 类型的参数正常处理
                $paramDoc = $this->getParamDoc($docComment, $param->getName());
                $params[] = [
                    'name' => $param->getName(),
                    'type' => $paramDoc['type'] ?? $paramType,
                    'required' => !$param->isOptional(),
                    'default' => $param->isOptional() ? ($param->getDefaultValue() ?? null) : null,
                    'description' => $paramDoc['description'] ?? ''
                ];
            }
            
            // 如果有 Request 参数，解析方法体中实际使用的参数
            if ($hasRequestParam) {
                $actualParams = $this->parseMethodBodyParams($method);
                // 合并实际参数（实际参数优先）
                $params = array_merge($params, $actualParams);
            }
        }

        // 获取返回值类型
        $returnType = $method->getReturnType();
        $returnTypeName = 'mixed';
        if ($returnType) {
            if ($returnType instanceof \ReflectionNamedType) {
                $returnTypeName = $returnType->getName();
            } elseif (method_exists($returnType, 'getName')) {
                $returnTypeName = $returnType->getName();
            }
        }

        // 构建 API 路径
        $apiPath = '/api/' . $controllerPath . '/' . $methodName;

        // 判断请求方法（根据方法名或注释）
        $requestMethod = $this->guessRequestMethod($methodName, $doc);

        return [
            'name' => $methodName,
            'title' => $doc['title'] ?? $methodName,
            'description' => $doc['description'] ?? '',
            'path' => $apiPath,
            'method' => $requestMethod,
            'params' => $params,
            'return' => [
                'type' => $returnTypeName,
                'description' => $doc['return'] ?? ''
            ],
            'url' => rtrim($baseUrl, '/') . $apiPath,
            'needLogin' => $needLogin
        ];
    }

    /**
     * 解析文档注释
     */
    protected function parseDocComment(?string $docComment): array
    {
        $result = [
            'title' => '',
            'description' => '',
            'return' => ''
        ];

        if (empty($docComment)) {
            return $result;
        }

        // 移除注释标记
        $docComment = preg_replace('/^\/\*\*|\*\/$|\*\s*/m', '', $docComment);
        $lines = explode("\n", $docComment);

        $description = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // 解析 @param
            if (preg_match('/@param\s+(\S+)\s+\$(\w+)\s*(.*)/', $line, $matches)) {
                // 参数解析在单独的方法中处理
                continue;
            }

            // 解析 @return
            if (preg_match('/@return\s+(\S+)\s*(.*)/', $line, $matches)) {
                $result['return'] = trim($matches[2]);
                continue;
            }

            // 解析其他标签
            if (strpos($line, '@') === 0) {
                continue;
            }

            // 普通描述
            if (empty($result['title']) && !empty($line)) {
                $result['title'] = $line;
            } else {
                $description[] = $line;
            }
        }

        $result['description'] = implode("\n", $description);
        return $result;
    }

    /**
     * 获取参数文档
     */
    protected function getParamDoc(?string $docComment, string $paramName): array
    {
        if (empty($docComment) || $docComment === false) {
            return [];
        }

        $pattern = '/@param\s+(\S+)\s+\$' . preg_quote($paramName, '/') . '\s*(.*)/';
        if (preg_match($pattern, $docComment, $matches)) {
            return [
                'type' => $matches[1] ?? 'mixed',
                'description' => trim($matches[2] ?? '')
            ];
        }

        return [];
    }

    /**
     * 从注释中解析所有参数（不包括 Request 类型）
     */
    protected function parseCommentParams(?string $docComment): array
    {
        $params = [];
        
        if (empty($docComment) || $docComment === false) {
            return $params;
        }
        
        // 匹配所有 @param 行
        // 格式：@param type $name description
        // 或：@param type $name description (可选)
        if (preg_match_all('/@param\s+(\S+)\s+\$(\w+)\s*(.*)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[1] ?? 'mixed';
                $name = $match[2] ?? '';
                $description = trim($match[3] ?? '');
                
                // 跳过 Request 类型的参数
                if (strpos($type, 'Request') !== false) {
                    continue;
                }
                
                // 判断是否必填（根据描述或类型）
                $required = true;
                if (stripos($description, '可选') !== false || 
                    stripos($description, 'optional') !== false ||
                    stripos($description, '非必填') !== false) {
                    $required = false;
                }
                
                // 提取默认值（如果描述中有）
                $default = null;
                if (preg_match('/默认[值：:]\s*([^\s，,。]+)/', $description, $defaultMatch)) {
                    $default = trim($defaultMatch[1], '\'"');
                }
                
                $params[] = [
                    'name' => $name,
                    'type' => $type,
                    'required' => $required,
                    'default' => $default,
                    'description' => $description
                ];
            }
        }
        
        return $params;
    }

    /**
     * 解析方法体中实际使用的参数
     */
    protected function parseMethodBodyParams(\ReflectionMethod $method): array
    {
        $params = [];
        $docComment = $method->getDocComment();
        $docComment = $docComment === false ? null : $docComment;
        
        try {
            // 获取方法体代码
            $filename = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            
            if (!$filename || !file_exists($filename)) {
                return $params;
            }
            
            $lines = file($filename);
            if ($startLine >= count($lines) || $endLine > count($lines)) {
                return $params;
            }
            
            // 提取方法体代码
            $methodBody = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
            
            // 解析 input() 调用
            // 匹配 input('param') 或 input("param") 或 input('param', 'default')
            if (preg_match_all("/input\s*\(\s*['\"]([^'\"]+)['\"]/", $methodBody, $matches)) {
                foreach ($matches[1] as $paramName) {
                    if (!isset($params[$paramName])) {
                        $paramDoc = $this->getParamDoc($docComment, $paramName);
                        $params[$paramName] = [
                            'name' => $paramName,
                            'type' => $paramDoc['type'] ?? 'string',
                            'required' => true,
                            'default' => null,
                            'description' => $paramDoc['description'] ?? ''
                        ];
                    }
                }
            }
            
            // 解析 request()->post('param') 或 request()->get('param')
            if (preg_match_all("/request\s*\(\)\s*->\s*(post|get)\s*\(\s*['\"]([^'\"]+)['\"]/", $methodBody, $matches)) {
                foreach ($matches[2] as $index => $paramName) {
                    if (!isset($params[$paramName])) {
                        $paramDoc = $this->getParamDoc($docComment, $paramName);
                        $params[$paramName] = [
                            'name' => $paramName,
                            'type' => $paramDoc['type'] ?? 'string',
                            'required' => $matches[1][$index] === 'post', // POST 通常必填
                            'default' => null,
                            'description' => $paramDoc['description'] ?? ''
                        ];
                    }
                }
            }
            
            // 解析 request()->post() 获取所有POST参数（通过变量赋值）
            // 例如：$post = request()->post();
            if (preg_match("/\$(\w+)\s*=\s*request\s*\(\)\s*->\s*post\s*\(\)/", $methodBody, $varMatch)) {
                $varName = $varMatch[1];
                // 查找这个变量被使用的地方，提取键名
                // 例如：$post['nickname'], $post['email'] 等
                $pattern = '/\$' . preg_quote($varName, '/') . '\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/';
                if (preg_match_all($pattern, $methodBody, $postMatches)) {
                    foreach ($postMatches[1] as $paramName) {
                        if (!isset($params[$paramName])) {
                            $paramDoc = $this->getParamDoc($docComment, $paramName);
                            $params[$paramName] = [
                                'name' => $paramName,
                                'type' => $paramDoc['type'] ?? 'string',
                                'required' => true,
                                'default' => null,
                                'description' => $paramDoc['description'] ?? ''
                            ];
                        }
                    }
                }
            }
            
            // 解析 $request->post('param') 或 $request->get('param')
            if (preg_match_all("/\$request\s*->\s*(post|get)\s*\(\s*['\"]([^'\"]+)['\"]/", $methodBody, $matches)) {
                foreach ($matches[2] as $index => $paramName) {
                    if (!isset($params[$paramName])) {
                        $paramDoc = $this->getParamDoc($docComment, $paramName);
                        $params[$paramName] = [
                            'name' => $paramName,
                            'type' => $paramDoc['type'] ?? 'string',
                            'required' => $matches[1][$index] === 'post',
                            'default' => null,
                            'description' => $paramDoc['description'] ?? ''
                        ];
                    }
                }
            }
            
            // 解析 input('param', defaultValue) 带默认值的情况
            if (preg_match_all("/input\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*([^,\)]+)/", $methodBody, $matches)) {
                foreach ($matches[1] as $index => $paramName) {
                    if (isset($params[$paramName])) {
                        // 如果已存在，更新默认值
                        $defaultValue = trim($matches[2][$index]);
                        // 移除引号
                        $defaultValue = trim($defaultValue, '\'"');
                        $params[$paramName]['default'] = $defaultValue;
                        $params[$paramName]['required'] = false;
                    }
                }
            }
            
        } catch (\Exception $e) {
            // 解析失败时返回空数组
            return $params;
        }
        
        return array_values($params);
    }

    /**
     * 猜测请求方法
     */
    protected function guessRequestMethod(string $methodName, array $doc): string
    {
        $methodNameLower = strtolower($methodName);
        
        // 根据方法名判断
        if (strpos($methodNameLower, 'get') === 0 || strpos($methodNameLower, 'list') === 0 || strpos($methodNameLower, 'index') === 0) {
            return 'GET';
        }
        
        if (strpos($methodNameLower, 'add') === 0 || strpos($methodNameLower, 'create') === 0 || strpos($methodNameLower, 'register') === 0) {
            return 'POST';
        }
        
        if (strpos($methodNameLower, 'edit') === 0 || strpos($methodNameLower, 'update') === 0 || strpos($methodNameLower, 'change') === 0) {
            return 'PUT';
        }
        
        if (strpos($methodNameLower, 'delete') === 0 || strpos($methodNameLower, 'del') === 0) {
            return 'DELETE';
        }

        // 默认 POST
        return 'POST';
    }

    /**
     * 生成 HTML 文档
     */
    protected function generateHtml(array $apiData, string $title, string $author, string $baseUrl = 'http://localhost'): string
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }
        .controller {
            background: white;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .controller-title {
            font-size: 1.8em;
            color: #667eea;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        .controller-desc {
            color: #666;
            margin-bottom: 20px;
        }
        .method {
            margin-bottom: 30px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 6px;
            border-left: 4px solid #667eea;
        }
        .method-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .method-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85em;
        }
        .method-badge.get {
            background: #10b981;
            color: white;
        }
        .method-badge.post {
            background: #3b82f6;
            color: white;
        }
        .method-badge.put {
            background: #f59e0b;
            color: white;
        }
        .method-badge.delete {
            background: #ef4444;
            color: white;
        }
        .method-badge.auth {
            background: #8b5cf6;
            color: white;
        }
        .method-badge.no-auth {
            background: #6b7280;
            color: white;
        }
        .method-title {
            font-size: 1.3em;
            color: #333;
            margin-right: 10px;
        }
        .method-path {
            font-family: 'Courier New', monospace;
            background: #e5e7eb;
            padding: 4px 8px;
            border-radius: 4px;
            color: #1f2937;
            font-size: 0.9em;
        }
        .method-desc {
            color: #666;
            margin-bottom: 15px;
        }
        .section {
            margin-top: 15px;
        }
        .section-title {
            font-size: 1.1em;
            color: #667eea;
            margin-bottom: 10px;
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        table tr:hover {
            background: #f9fafb;
        }
        .required {
            color: #ef4444;
            font-weight: bold;
        }
        .optional {
            color: #6b7280;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            margin-top: 40px;
        }
        .toc {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .toc h2 {
            color: #667eea;
            margin-bottom: 15px;
        }
        .toc ul {
            list-style: none;
        }
        .toc li {
            margin: 8px 0;
        }
        .toc a {
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s;
        }
        .toc a:hover {
            color: #764ba2;
            text-decoration: underline;
        }
        .config-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .config-panel h2 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        .config-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .config-group {
            flex: 1;
            min-width: 200px;
        }
        .config-group label {
            display: block;
            margin-bottom: 5px;
            color: #374151;
            font-weight: 500;
            font-size: 0.9em;
        }
        .config-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9em;
            transition: border-color 0.3s;
        }
        .config-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .test-panel {
            margin-top: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .test-panel h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.1em;
        }
        .param-inputs {
            margin-bottom: 15px;
        }
        .param-input-group {
            margin-bottom: 12px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .param-input-group label {
            min-width: 100px;
            color: #374151;
            font-weight: 500;
            font-size: 0.9em;
        }
        .param-input-group input,
        .param-input-group textarea {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.9em;
            font-family: 'Courier New', monospace;
        }
        .param-input-group input:focus,
        .param-input-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .param-input-group textarea {
            min-height: 60px;
            resize: vertical;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-primary:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        .response-panel {
            margin-top: 15px;
            padding: 15px;
            background: #1f2937;
            border-radius: 4px;
            display: none;
        }
        .response-panel.show {
            display: block;
        }
        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            color: #9ca3af;
            font-size: 0.85em;
        }
        .response-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        .response-status.success {
            background: #10b981;
            color: white;
        }
        .response-status.error {
            background: #ef4444;
            color: white;
        }
        .response-content {
            color: #e5e7eb;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow-y: auto;
        }
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #667eea;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <p>作者: {$author} | 生成时间: {$this->getCurrentTime()}</p>
        </div>

        <div class="config-panel">
            <h2>全局设置</h2>
            <div class="config-form">
                <div class="config-group">
                    <label for="global-domain">API 域名</label>
                    <input type="text" id="global-domain" placeholder="留空则使用当前网页域名" value="">
                </div>
                <div class="config-group">
                    <label for="global-token">Token (可选)</label>
                    <input type="text" id="global-token" placeholder="输入您的认证 Token">
                </div>
            </div>
        </div>

        <div class="toc">
            <h2>目录</h2>
            <ul>
HTML;

        // 生成目录
        foreach ($apiData as $index => $controller) {
            $anchor = 'controller-' . $index;
            $html .= "<li><a href=\"#{$anchor}\">{$controller['title']}</a></li>";
        }

        $html .= <<<HTML
            </ul>
        </div>

HTML;

        // 生成控制器和方法文档
        foreach ($apiData as $index => $controller) {
            $anchor = 'controller-' . $index;
            $html .= "<div class=\"controller\" id=\"{$anchor}\">";
            $html .= "<h2 class=\"controller-title\">{$controller['title']}</h2>";
            
            if (!empty($controller['description'])) {
                $html .= "<p class=\"controller-desc\">{$controller['description']}</p>";
            }

            foreach ($controller['methods'] as $method) {
                $html .= $this->generateMethodHtml($method);
            }

            $html .= "</div>";
        }

        $html .= <<<HTML
        <div class="footer">
            <p>© {$this->getCurrentYear()} {$author}. All rights reserved.</p>
        </div>
    </div>
    <script>
        // 保存全局配置到 localStorage
        function saveConfig() {
            localStorage.setItem('api_doc_domain', document.getElementById('global-domain').value);
            localStorage.setItem('api_doc_token', document.getElementById('global-token').value);
        }

        // 加载保存的配置
        function loadConfig() {
            const savedDomain = localStorage.getItem('api_doc_domain');
            const savedToken = localStorage.getItem('api_doc_token');
            if (savedDomain) {
                document.getElementById('global-domain').value = savedDomain;
            }
            if (savedToken) {
                document.getElementById('global-token').value = savedToken;
            }
        }
        
        // 获取当前使用的域名（用于显示）
        function getCurrentDomain() {
            const domain = document.getElementById('global-domain').value.trim();
            return domain || window.location.origin;
        }

        // 发送请求
        function sendRequest(methodId, method, path) {
            let domain = document.getElementById('global-domain').value.trim();
            // 如果域名为空，使用当前网页的域名
            if (!domain) {
                domain = window.location.origin;
            }
            const token = document.getElementById('global-token').value.trim();
            const url = domain.replace(/\/$/, '') + path;
            
            // 获取参数值
            const params = {};
            const paramInputs = document.querySelectorAll('#method-' + methodId + ' .param-input-group input, #method-' + methodId + ' .param-input-group textarea');
            paramInputs.forEach(input => {
                const paramName = input.getAttribute('data-param');
                if (paramName && input.value.trim()) {
                    const value = input.value.trim();
                    try {
                        // 尝试解析 JSON（如果以 { 或 [ 开头）
                        if (value.startsWith('{') || value.startsWith('[')) {
                            params[paramName] = JSON.parse(value);
                        } else {
                            // 如果不是 JSON，直接使用字符串值
                            params[paramName] = value;
                        }
                    } catch (e) {
                        // 解析失败，使用原始字符串值
                        params[paramName] = value;
                    }
                }
            });

            // 显示加载状态
            const btn = document.getElementById('btn-' + methodId);
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span>发送中...';

            // 准备请求选项
            const options = {
                method: method,
                headers: {
                    'Accept': 'application/json'
                }
            };

            // 添加 Token
            if (token) {
                options.headers['Authorization'] = 'Bearer ' + token;
                options.headers['Token'] = token;
            }

            // 根据请求方法处理参数
            if (method === 'GET' || method === 'DELETE') {
                // GET/DELETE 请求将参数添加到 URL，不设置 Content-Type
                const queryParams = new URLSearchParams();
                Object.keys(params).forEach(key => {
                    if (typeof params[key] === 'object') {
                        queryParams.append(key, JSON.stringify(params[key]));
                    } else {
                        queryParams.append(key, params[key]);
                    }
                });
                const queryString = queryParams.toString();
                const finalUrl = queryString ? url + '?' + queryString : url;
                fetch(finalUrl, options)
                    .then(response => handleResponse(response, methodId))
                    .catch(error => handleError(error, methodId))
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            } else {
                // POST/PUT 请求将参数放在请求体中
                options.headers['Content-Type'] = 'application/json';
                if (Object.keys(params).length > 0) {
                    options.body = JSON.stringify(params);
                }
                fetch(url, options)
                    .then(response => handleResponse(response, methodId))
                    .catch(error => handleError(error, methodId))
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
            }
        }

        // 处理响应
        async function handleResponse(response, methodId) {
            const responsePanel = document.getElementById('response-' + methodId);
            const statusEl = document.getElementById('status-' + methodId);
            const contentEl = document.getElementById('content-' + methodId);
            
            responsePanel.classList.add('show');
            
            const status = response.status;
            const statusText = response.statusText;
            
            statusEl.textContent = status + ' ' + statusText;
            statusEl.className = 'response-status ' + (status >= 200 && status < 300 ? 'success' : 'error');
            
            try {
                const contentType = response.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                    contentEl.textContent = JSON.stringify(data, null, 2);
                } else {
                    data = await response.text();
                    contentEl.textContent = data;
                }
            } catch (e) {
                contentEl.textContent = '无法解析响应: ' + e.message;
            }
        }

        // 处理错误
        function handleError(error, methodId) {
            const responsePanel = document.getElementById('response-' + methodId);
            const statusEl = document.getElementById('status-' + methodId);
            const contentEl = document.getElementById('content-' + methodId);
            
            responsePanel.classList.add('show');
            statusEl.textContent = '请求失败';
            statusEl.className = 'response-status error';
            contentEl.textContent = '错误信息: ' + error.message;
        }

        // 页面加载时恢复配置
        window.addEventListener('DOMContentLoaded', function() {
            loadConfig();
            
            // 监听配置变化
            document.getElementById('global-domain').addEventListener('change', saveConfig);
            document.getElementById('global-domain').addEventListener('blur', saveConfig);
            document.getElementById('global-token').addEventListener('change', saveConfig);
            document.getElementById('global-token').addEventListener('blur', saveConfig);
        });
    </script>
</body>
</html>
HTML;

        return $html;
    }

    /**
     * 生成方法 HTML
     */
    protected function generateMethodHtml(array $method): string
    {
        $methodClass = strtolower($method['method']);
        $methodId = md5($method['path'] . $method['method']);
        $needLogin = $method['needLogin'] ?? true;
        $authBadge = $needLogin ? '<span class="method-badge auth">需要登录</span>' : '<span class="method-badge no-auth">无需登录</span>';
        
        $html = "<div class=\"method\" id=\"method-{$methodId}\">";
        $html .= "<div class=\"method-header\">";
        $html .= "<span class=\"method-badge {$methodClass}\">{$method['method']}</span>";
        $html .= $authBadge;
        $html .= "<span class=\"method-title\">{$method['title']}</span>";
        $html .= "<span class=\"method-path\">{$method['path']}</span>";
        $html .= "</div>";

        if (!empty($method['description'])) {
            $html .= "<p class=\"method-desc\">{$method['description']}</p>";
        }

        // 请求参数
        if (!empty($method['params'])) {
            $html .= "<div class=\"section\">";
            $html .= "<div class=\"section-title\">请求参数</div>";
            $html .= "<table>";
            $html .= "<thead><tr><th>参数名</th><th>类型</th><th>必填</th><th>默认值</th><th>说明</th></tr></thead>";
            $html .= "<tbody>";
            
            foreach ($method['params'] as $param) {
                $required = $param['required'] ? '<span class="required">是</span>' : '<span class="optional">否</span>';
                $default = $param['default'] !== null ? htmlspecialchars(json_encode($param['default'])) : '-';
                $html .= "<tr>";
                $html .= "<td><code>{$param['name']}</code></td>";
                $html .= "<td>{$param['type']}</td>";
                $html .= "<td>{$required}</td>";
                $html .= "<td>{$default}</td>";
                $html .= "<td>{$param['description']}</td>";
                $html .= "</tr>";
            }
            
            $html .= "</tbody></table>";
            $html .= "</div>";
        }

        // 返回说明
        $html .= "<div class=\"section\">";
        $html .= "<div class=\"section-title\">返回说明</div>";
        $html .= "<p>返回类型: <code>{$method['return']['type']}</code></p>";
        if (!empty($method['return']['description'])) {
            $html .= "<p>{$method['return']['description']}</p>";
        }
        $html .= "</div>";

        // API 地址
        $html .= "<div class=\"section\">";
        $html .= "<div class=\"section-title\">请求地址</div>";
        $html .= "<p><code>{$method['url']}</code></p>";
        $html .= "</div>";

        // 测试面板
        $html .= "<div class=\"test-panel\">";
        $html .= "<h3>接口测试</h3>";
        
        // 参数输入框
        if (!empty($method['params'])) {
            $html .= "<div class=\"param-inputs\">";
            foreach ($method['params'] as $param) {
                $requiredAttr = $param['required'] ? 'required' : '';
                $defaultValue = $param['default'] !== null ? htmlspecialchars(json_encode($param['default'])) : '';
                $placeholder = $param['description'] ? htmlspecialchars($param['description']) : '请输入' . $param['name'];
                
                // 判断是否为复杂类型（对象或数组），使用 textarea
                $isComplexType = in_array(strtolower($param['type']), ['array', 'object', 'mixed']) || 
                                 strpos(strtolower($param['type']), '[]') !== false;
                
                if ($isComplexType) {
                    $html .= "<div class=\"param-input-group\">";
                    $html .= "<label for=\"param-{$methodId}-{$param['name']}\">{$param['name']} <span class=\"required\">*</span></label>";
                    $html .= "<textarea id=\"param-{$methodId}-{$param['name']}\" data-param=\"{$param['name']}\" placeholder=\"{$placeholder}\" {$requiredAttr}></textarea>";
                    $html .= "</div>";
                } else {
                    $html .= "<div class=\"param-input-group\">";
                    $html .= "<label for=\"param-{$methodId}-{$param['name']}\">{$param['name']}" . ($param['required'] ? ' <span class="required">*</span>' : '') . "</label>";
                    $html .= "<input type=\"text\" id=\"param-{$methodId}-{$param['name']}\" data-param=\"{$param['name']}\" placeholder=\"{$placeholder}\" value=\"{$defaultValue}\" {$requiredAttr}>";
                    $html .= "</div>";
                }
            }
            $html .= "</div>";
        } else {
            $html .= "<p style=\"color: #6b7280; margin-bottom: 15px;\">此接口无需参数</p>";
        }
        
        // 发送按钮
        $html .= "<button class=\"btn btn-primary\" id=\"btn-{$methodId}\" onclick=\"sendRequest('{$methodId}', '{$method['method']}', '{$method['path']}')\">发送请求</button>";
        
        // 响应面板
        $html .= "<div class=\"response-panel\" id=\"response-{$methodId}\">";
        $html .= "<div class=\"response-header\">";
        $html .= "<span>响应结果</span>";
        $html .= "<span class=\"response-status\" id=\"status-{$methodId}\"></span>";
        $html .= "</div>";
        $html .= "<div class=\"response-content\" id=\"content-{$methodId}\"></div>";
        $html .= "</div>";
        
        $html .= "</div>"; // test-panel

        $html .= "</div>"; // method
        return $html;
    }

    /**
     * 获取当前时间
     */
    protected function getCurrentTime(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 获取当前年份
     */
    protected function getCurrentYear(): string
    {
        return date('Y');
    }
}

