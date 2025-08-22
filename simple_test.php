<?php

declare(strict_types=1);

echo "=== CarbonTrack 简单测试 ===\n\n";

// 测试1: 基本PHP功能
echo "1. 测试基本PHP功能...\n";
echo "   PHP版本: " . PHP_VERSION . "\n";
echo "   当前时间: " . date('Y-m-d H:i:s') . "\n";
echo "   工作目录: " . getcwd() . "\n";
echo "   ✓ 基本PHP功能正常\n\n";

// 测试2: 自动加载
echo "2. 测试自动加载...\n";
try {
    require __DIR__ . '/vendor/autoload.php';
    echo "   ✓ 自动加载正常\n\n";
} catch (Exception $e) {
    echo "   ✗ 自动加载失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试3: 依赖注入容器
echo "3. 测试依赖注入容器...\n";
try {
    $container = new \DI\Container();
    echo "   ✓ 容器创建成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 容器创建失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试4: Slim应用
echo "4. 测试Slim应用...\n";
try {
    \Slim\Factory\AppFactory::setContainer($container);
    $app = \Slim\Factory\AppFactory::create();
    echo "   ✓ Slim应用创建成功\n\n";
} catch (Exception $e) {
    echo "   ✗ Slim应用创建失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试5: 路由注册
echo "5. 测试路由注册...\n";
try {
    $app->get('/test', function ($request, $response) {
        $response->getBody()->write('Test route working!');
        return $response;
    });
    echo "   ✓ 测试路由注册成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 测试路由注册失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试6: 中间件
echo "6. 测试中间件...\n";
try {
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    echo "   ✓ 中间件添加成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 中间件添加失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试7: 项目路由
echo "7. 测试项目路由...\n";
try {
    $routes = require __DIR__ . '/src/routes.php';
    $routes($app);
    echo "   ✓ 项目路由注册成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 项目路由注册失败: " . $e->getMessage() . "\n\n";
    echo "   错误详情:\n";
    echo "   " . $e->getTraceAsString() . "\n\n";
    exit(1);
}

echo "🎉 所有测试通过！应用程序应该可以正常运行。\n";
echo "现在可以访问以下路径:\n";
echo "- / (健康检查)\n";
echo "- /api/v1 (API根路径)\n";
echo "- /debug (调试路径)\n";
echo "- /test (测试路径)\n";
