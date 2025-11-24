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
        // 生成左侧菜单（树形结构，包含所有接口方法）
        $menuItems = '';
        foreach ($apiData as $index => $controller) {
            $controllerAnchor = 'controller-' . $index;
            $controllerTitle = htmlspecialchars($controller['title']);
            
            // 如果有方法，生成可展开的菜单项
            if (!empty($controller['methods'])) {
                $menuItems .= "<li class=\"layui-nav-item\">";
                $menuItems .= "<a href=\"javascript:;\">";
                $menuItems .= "<cite>{$controllerTitle}</cite>";
                $menuItems .= "<i class=\"layui-icon layui-icon-down layui-nav-more\"></i>";
                $menuItems .= "</a>";
                $menuItems .= "<dl class=\"layui-nav-child\">";
                
                // 添加控制器标题（点击跳转到控制器）
                $menuItems .= "<dd><a href=\"javascript:;\" onclick=\"scrollToController('{$controllerAnchor}')\">{$controllerTitle}</a></dd>";
                
                // 添加所有方法
                foreach ($controller['methods'] as $methodIndex => $method) {
                    $methodId = md5($method['path'] . $method['method']);
                    $methodTitle = htmlspecialchars($method['title'] ?: $method['name']);
                    $methodBadge = strtoupper($method['method']);
                    $menuItems .= "<dd><a href=\"javascript:;\" onclick=\"scrollToMethod('method-{$methodId}')\">";
                    $menuItems .= "<span class=\"method-badge-small method-badge-{$method['method']}\">{$methodBadge}</span> ";
                    $menuItems .= "{$methodTitle}";
                    $menuItems .= "</a></dd>";
                }
                
                $menuItems .= "</dl>";
                $menuItems .= "</li>";
            } else {
                // 如果没有方法，只显示控制器
                $menuItems .= "<li class=\"layui-nav-item\"><a href=\"javascript:;\" onclick=\"scrollToController('{$controllerAnchor}')\">{$controllerTitle}</a></li>";
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link href="/static/system/layui/css/layui.css" rel="stylesheet" type="text/css"/>
    <link href="/static/system/css/style.css" rel="stylesheet" type="text/css"/>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f2f2f2;
        }
        .layui-layout-admin {
            display: block !important;
        }
        .layui-side {
            width: 238px;
            transition: width 0.3s;
            overflow: hidden;
            display: block !important;
        }
        /* 确保在所有屏幕尺寸下侧边栏默认显示（除了移动端） */
        @media screen and (min-width: 769px) {
            .layui-side {
                display: block !important;
                transform: none !important;
            }
        }
        .layui-side-scroll {
            width: 100%;
            height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .layui-side .layui-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 50px;
            padding: 0 15px;
            box-sizing: border-box;
            background-color: #191a23;
            border-bottom: 1px solid #2d2f3a;
            white-space: nowrap;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
        }
        .layui-side .layui-logo span {
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: center;
        }
        .method-badge-small {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 600;
            margin-right: 6px;
            vertical-align: middle;
        }
        .method-badge-small.method-badge-get {
            background: #10b981;
            color: white;
        }
        .method-badge-small.method-badge-post {
            background: #3b82f6;
            color: white;
        }
        .method-badge-small.method-badge-put {
            background: #f59e0b;
            color: white;
        }
        .method-badge-small.method-badge-delete {
            background: #ef4444;
            color: white;
        }
        .layui-nav-tree .layui-nav-child dd a {
            padding-left: 45px;
            font-size: 13px;
        }
        .layui-side-menu .layui-nav {
            margin-top: 0 !important;
        }
        .layui-header .layui-logo {
            display: none;
        }
        .layui-header .header-logo {
            display: none;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            padding: 0 15px;
            line-height: 50px;
        }
        .layui-layout-left {
            left: 238px;
            transition: left 0.3s;
        }
        .layui-body, .layui-footer {
            left: 238px;
            transition: left 0.3s;
        }
        .api-header-config-btn {
            padding: 0 15px;
        }
        .api-header-config-btn i {
            font-size: 18px;
        }
        /* 收缩状态下的样式 - 优先级最高 */
        .layui-side.collapsed ~ .layui-header .layui-layout-left,
        .layui-side.collapsed ~ .layui-body,
        .layui-side.collapsed ~ .layui-footer {
            left: 0 !important;
        }
        /* 平板端样式 */
        @media screen and (min-width: 769px) and (max-width: 1024px) {
            .layui-side:not(.collapsed) {
                width: 238px;
            }
            .layui-side.collapsed {
                width: 0 !important;
            }
            .layui-layout-left {
                left: 238px;
            }
            .layui-body, .layui-footer {
                left: 238px;
            }
            /* 收缩状态下覆盖 */
            .layui-side.collapsed ~ .layui-header .layui-layout-left,
            .layui-side.collapsed ~ .layui-body,
            .layui-side.collapsed ~ .layui-footer {
                left: 0 !important;
            }
        }
        /* 手机端样式 */
        @media screen and (max-width: 768px) {
            .layui-side .layui-logo {
                display: none !important;
            }
            .layui-header .header-logo {
                display: block !important;
            }
            .layui-layout-left {
                left: 0 !important;
            }
            .layui-body, .layui-footer {
                left: 0 !important;
            }
        }
        .api-content {
            padding: 15px;
        }
        .controller {
            background: white;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .controller-title {
            font-size: 18px;
            color: #1890ff;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e8e8e8;
            font-weight: 600;
        }
        .controller-desc {
            color: #666;
            margin-bottom: 20px;
        }
        .method {
            margin-bottom: 20px;
            padding: 15px;
            background: #fafafa;
            border-radius: 2px;
            border-left: 3px solid #1890ff;
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
            border-radius: 2px;
            font-weight: 600;
            font-size: 12px;
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
            font-size: 16px;
            color: #333;
            margin-right: 10px;
            font-weight: 600;
        }
        .method-path {
            font-family: 'Courier New', monospace;
            background: #e5e7eb;
            padding: 4px 8px;
            border-radius: 2px;
            color: #1f2937;
            font-size: 12px;
        }
        .method-desc {
            color: #666;
            margin-bottom: 15px;
        }
        .section {
            margin-top: 15px;
        }
        .section-title {
            font-size: 14px;
            color: #1890ff;
            margin-bottom: 10px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e8e8e8;
        }
        table th {
            background: #fafafa;
            font-weight: 600;
            color: #333;
        }
        table tr:hover {
            background: #fafafa;
        }
        .required {
            color: #ef4444;
            font-weight: 600;
        }
        .optional {
            color: #6b7280;
        }
        .test-panel {
            margin-top: 15px;
            padding: 15px;
            background: #fafafa;
            border-radius: 2px;
            border: 1px solid #e8e8e8;
        }
        .test-panel h3 {
            color: #1890ff;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 600;
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
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        .param-input-group input,
        .param-input-group textarea {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #d9d9d9;
            border-radius: 2px;
            font-size: 14px;
            font-family: 'Courier New', monospace;
        }
        .param-input-group input:focus,
        .param-input-group textarea:focus {
            outline: none;
            border-color: #1890ff;
        }
        .param-input-group textarea {
            min-height: 60px;
            resize: vertical;
        }
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 2px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #1890ff;
            color: white;
        }
        .btn-primary:hover {
            background: #40a9ff;
        }
        .btn-primary:disabled {
            background: #d9d9d9;
            cursor: not-allowed;
        }
        .response-panel {
            margin-top: 15px;
            padding: 15px;
            background: #1f2937;
            border-radius: 2px;
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
            font-size: 12px;
        }
        .response-status {
            padding: 2px 8px;
            border-radius: 2px;
            font-weight: 600;
            font-size: 12px;
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
            font-size: 12px;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 400px;
            overflow-y: auto;
        }
        .loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #1890ff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media screen and (max-width: 768px) {
            .layui-side .layui-logo {
                display: flex !important;
            }
            .layui-layout-admin .layui-body {
                left: 0 !important;
            }
            .layui-side {
                transform: translateX(-100%);
                transition: transform 0.3s;
                width: 238px !important;
                position: fixed;
                z-index: 999;
                height: 100%;
                top: 50px;
            }
            .layui-side.show {
                transform: translateX(0);
            }
            /* 移动端遮罩层 */
            .layui-side-mask {
                display: none;
                position: fixed;
                top: 50px;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 998;
            }
            .layui-side.show ~ .layui-side-mask,
            .layui-side.show + * + .layui-side-mask {
                display: block;
            }
        }
    </style>
</head>
<body class="layui-layout-body">
<div class="layui-layout layui-layout-admin">
    <!-- 头部区域 -->
    <div class="layui-header">
        <div class="header-logo">{$title}</div>
        <ul class="layui-nav layui-layout-left">
            <li class="layui-nav-item layadmin-flexible" lay-unselect>
                <a href="javascript:;" id="flexible" title="侧边伸缩">
                    <i class="layui-icon layui-icon-shrink-right"></i>
                </a>
            </li>
        </ul>
        <ul class="layui-nav layui-layout-right">
            <li class="layui-nav-item api-header-config-btn" lay-unselect>
                <a href="javascript:;" id="api-config-btn" title="API配置">
                    <i class="layui-icon layui-icon-set"></i>
                </a>
            </li>
        </ul>
    </div>
    <!-- 侧边菜单 -->
    <div class="layui-side layui-side-menu">
        <div class="layui-side-scroll">
            <div class="layui-logo">
                <span>{$title}</span>
            </div>
            <ul class="layui-nav layui-nav-tree" lay-shrink="all" lay-filter="api-menu">
                {$menuItems}
            </ul>
        </div>
    </div>
    <!-- 移动端遮罩层 -->
    <div class="layui-side-mask"></div>
    <!-- 内容主体区域 -->
    <div class="layui-body">
        <div class="api-content">
HTML;

        // 生成控制器和方法文档
        foreach ($apiData as $index => $controller) {
            $anchor = 'controller-' . $index;
            $html .= "<div class=\"controller\" id=\"{$anchor}\">";
            $html .= "<h2 class=\"controller-title\">" . htmlspecialchars($controller['title']) . "</h2>";
            
            if (!empty($controller['description'])) {
                $html .= "<p class=\"controller-desc\">" . htmlspecialchars($controller['description']) . "</p>";
            }

            foreach ($controller['methods'] as $method) {
                $html .= $this->generateMethodHtml($method);
            }

            $html .= "</div>";
        }

        $html .= <<<HTML
        </div>
    </div>
    <!-- 底部固定区域 -->
    <div class="layui-footer">
        copyright © {$this->getCurrentYear()} <a href="http://www.swiftadmin.net" target="_blank">{$author}</a> all rights reserved.
    </div>
</div>
<!-- 隐藏的配置输入框（用于存储配置值） -->
<input type="hidden" id="global-domain" value="">
<input type="hidden" id="global-token" value="">
<script src="/static/system/layui/layui.js"></script>
<script>
        let configLayer = null;
        
        // 保存全局配置到 localStorage
        function saveConfig() {
            // 优先从弹窗输入框获取值，如果没有则从隐藏输入框获取
            var domainEl = document.getElementById('config-domain') || document.getElementById('global-domain');
            var tokenEl = document.getElementById('config-token') || document.getElementById('global-token');
            const domain = domainEl ? domainEl.value : '';
            const token = tokenEl ? tokenEl.value : '';
            localStorage.setItem('api_doc_domain', domain);
            localStorage.setItem('api_doc_token', token);
            
            // 同步到隐藏输入框（如果存在）
            var globalDomainEl = document.getElementById('global-domain');
            var globalTokenEl = document.getElementById('global-token');
            if (globalDomainEl) globalDomainEl.value = domain;
            if (globalTokenEl) globalTokenEl.value = token;
        }

        // 加载保存的配置
        function loadConfig() {
            const savedDomain = localStorage.getItem('api_doc_domain');
            const savedToken = localStorage.getItem('api_doc_token');
            var globalDomainEl = document.getElementById('global-domain');
            var globalTokenEl = document.getElementById('global-token');
            if (globalDomainEl && savedDomain) {
                globalDomainEl.value = savedDomain;
            }
            if (globalTokenEl && savedToken) {
                globalTokenEl.value = savedToken;
            }
        }
        
        // 获取当前使用的域名（用于显示）
        function getCurrentDomain() {
            var domainEl = document.getElementById('config-domain') || document.getElementById('global-domain');
            const domain = domainEl ? domainEl.value.trim() : '';
            return domain || window.location.origin;
        }
        
        // 获取配置值
        function getConfig() {
            var domainEl = document.getElementById('config-domain') || document.getElementById('global-domain');
            var tokenEl = document.getElementById('config-token') || document.getElementById('global-token');
            return {
                domain: (domainEl ? domainEl.value.trim() : '') || window.location.origin,
                token: tokenEl ? tokenEl.value.trim() : ''
            };
        }

        // 发送请求
        function sendRequest(methodId, method, path) {
            const config = getConfig();
            const domain = config.domain.replace(/\/$/, '');
            const token = config.token;
            const url = domain + path;
            
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

        // 滚动到指定控制器
        function scrollToController(controllerId) {
            const element = document.getElementById(controllerId);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                // 移动端点击后关闭侧边栏
                if (window.innerWidth <= 768) {
                    document.querySelector('.layui-side').classList.remove('show');
                }
            }
        }
        
        // 滚动到指定方法
        function scrollToMethod(methodId) {
            const element = document.getElementById(methodId);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                // 高亮显示该方法
                element.style.backgroundColor = '#e6f7ff';
                setTimeout(function() {
                    element.style.backgroundColor = '';
                }, 2000);
                // 移动端点击后关闭侧边栏
                if (window.innerWidth <= 768) {
                    document.querySelector('.layui-side').classList.remove('show');
                }
            }
        }

        // 页面加载时恢复配置和初始化 layui
        window.addEventListener('DOMContentLoaded', function() {
            loadConfig();

            // 初始化 layui
            layui.use(['element', 'layer', 'form'], function(){
                var element = layui.element;
                var layer = layui.layer;
                var form = layui.form;
                var $ = layui.$;
                
                // 打开配置弹窗
                $('#api-config-btn').on('click', function() {
                    var configHtml = '<form class="layui-form" lay-filter="api-config-form" style="padding: 20px;">' +
                        '<div class="layui-form-item">' +
                        '<label class="layui-form-label">ApiUrl:</label>' +
                        '<div class="layui-input-block">' +
                        '<input type="text" name="domain" id="config-domain" placeholder="留空则使用当前网页域名" autocomplete="off" class="layui-input">' +
                        '</div>' +
                        '</div>' +
                        '<div class="layui-form-item">' +
                        '<label class="layui-form-label">Token:</label>' +
                        '<div class="layui-input-block">' +
                        '<input type="text" name="token" id="config-token" placeholder="输入您的认证 Token" autocomplete="off" class="layui-input">' +
                        '</div>' +
                        '</div>' +
                        '<div class="layui-form-item">' +
                        '<div class="layui-input-block">' +
                        '<button type="button" class="layui-btn" id="save-config-btn">保存</button>' +
                        '<button type="button" class="layui-btn layui-btn-primary" id="cancel-config-btn">取消</button>' +
                        '</div>' +
                        '</div>' +
                        '</form>';
                    
                    // 根据屏幕宽度设置弹窗大小
                    var screenWidth = window.innerWidth;
                    var isMobile = screenWidth <= 768;
                    var areaWidth = isMobile ? ['90%', 'auto'] : ['500px', '300px'];
                    var maxWidth = isMobile ? screenWidth - 40 + 'px' : '500px';
                    
                    configLayer = layer.open({
                        type: 1,
                        title: 'API 配置',
                        content: configHtml,
                        area: areaWidth,
                        maxWidth: maxWidth,
                        success: function(layero, index) {
                            // 移动端优化样式
                            if (isMobile) {
                                $(layero).find('.layui-layer-content').css({
                                    'padding': '15px',
                                    'max-height': (window.innerHeight - 100) + 'px',
                                    'overflow-y': 'auto'
                                });
                            }
                            // 加载已保存的配置到弹窗中的输入框
                            var savedDomain = localStorage.getItem('api_doc_domain');
                            var savedToken = localStorage.getItem('api_doc_token');
                            if (savedDomain) {
                                $('#config-domain').val(savedDomain);
                            }
                            if (savedToken) {
                                $('#config-token').val(savedToken);
                            }
                            
                            // 保存配置（从弹窗输入框保存到页面输入框和localStorage）
                            $('#save-config-btn').off('click').on('click', function() {
                                var domain = $('#config-domain').val();
                                var token = $('#config-token').val();
                                // 同步到隐藏输入框（如果存在）
                                var globalDomainEl = document.getElementById('global-domain');
                                var globalTokenEl = document.getElementById('global-token');
                                if (globalDomainEl) globalDomainEl.value = domain;
                                if (globalTokenEl) globalTokenEl.value = token;
                                localStorage.setItem('api_doc_domain', domain);
                                localStorage.setItem('api_doc_token', token);
                                layer.close(configLayer);
                                layer.msg('配置已保存', {icon: 1});
                                configLayer = null;
                            });
                            
                            // 取消配置
                            $('#cancel-config-btn').off('click').on('click', function() {
                                layer.close(configLayer);
                                configLayer = null;
                            });
                        },
                        end: function() {
                            configLayer = null;
                        }
                    });
                });
                
                // 侧边菜单伸缩
                $('#flexible').on('click', function(e) {
                    e.stopPropagation();
                    var side = $('.layui-side');
                    var layoutLeft = $('.layui-layout-left');
                    var layuiBody = $('.layui-body');
                    var layuiFooter = $('.layui-footer');
                    var header = $('.layui-header');
                    var mask = $('.layui-side-mask');
                    
                    if (window.innerWidth <= 768) {
                        // 移动端：切换侧边栏显示/隐藏
                        side.toggleClass('show');
                        if (side.hasClass('show')) {
                            mask.show();
                        } else {
                            mask.hide();
                        }
                    } else {
                        // 桌面端和平板端：切换布局
                        var isCollapsed = side.hasClass('collapsed');
                        
                        if (!isCollapsed) {
                            // 收缩：隐藏侧边栏（logo 会一起隐藏，因为它在侧边栏内部）
                            side.addClass('collapsed').css('width', '0');
                            // 使用 attr 设置 style 来确保优先级
                            layoutLeft.attr('style', 'left: 0 !important; transition: left 0.3s;');
                            layuiBody.attr('style', 'left: 0 !important; transition: left 0.3s;');
                            layuiFooter.attr('style', 'left: 0 !important; transition: left 0.3s;');
                            $('#flexible i').removeClass('layui-icon-shrink-right').addClass('layui-icon-spread-left');
                        } else {
                            // 展开：显示侧边栏
                            side.removeClass('collapsed').css('width', '238px');
                            // 移除内联样式，让 CSS 规则生效
                            layoutLeft.removeAttr('style').css('left', '238px');
                            layuiBody.removeAttr('style').css('left', '238px');
                            layuiFooter.removeAttr('style').css('left', '238px');
                            $('#flexible i').removeClass('layui-icon-spread-left').addClass('layui-icon-shrink-right');
                        }
                    }
                });
                
                // 移动端点击内容区域或遮罩层关闭侧边栏
                $(document).on('click', '.layui-body, .layui-side-mask', function() {
                    if (window.innerWidth <= 768) {
                        $('.layui-side').removeClass('show');
                        $('.layui-side-mask').hide();
                    }
                });
                
                // 阻止侧边栏内部点击事件冒泡
                $('.layui-side').on('click', function(e) {
                    e.stopPropagation();
                });
            });
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

