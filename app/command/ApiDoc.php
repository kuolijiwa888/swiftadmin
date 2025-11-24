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
        $html = $this->generateHtml($apiData, $title, $author);

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
                $methodDoc = $this->parseMethodDoc($method, $controllerPath, $baseUrl);
                
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
    protected function parseMethodDoc(ReflectionMethod $method, string $controllerPath, string $baseUrl): ?array
    {
        $methodName = $method->getName();
        $docComment = $method->getDocComment();
        $docComment = $docComment === false ? null : $docComment;
        $doc = $this->parseDocComment($docComment);

        // 获取参数信息
        $params = [];
        $parameters = $method->getParameters();
        foreach ($parameters as $param) {
            $paramDoc = $this->getParamDoc($docComment, $param->getName());
            $paramType = 'mixed';
            if ($param->getType()) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $paramType = $type->getName();
                } elseif (method_exists($type, 'getName')) {
                    $paramType = $type->getName();
                }
            }
            $params[] = [
                'name' => $param->getName(),
                'type' => $paramType,
                'required' => !$param->isOptional(),
                'default' => $param->isOptional() ? ($param->getDefaultValue() ?? null) : null,
                'description' => $paramDoc['description'] ?? ''
            ];
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
            'url' => rtrim($baseUrl, '/') . $apiPath
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
    protected function generateHtml(array $apiData, string $title, string $author): string
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$title}</h1>
            <p>作者: {$author} | 生成时间: {$this->getCurrentTime()}</p>
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
        $html = "<div class=\"method\">";
        $html .= "<div class=\"method-header\">";
        $html .= "<span class=\"method-badge {$methodClass}\">{$method['method']}</span>";
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

        $html .= "</div>";
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

