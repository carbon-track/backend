<?php

declare(strict_types=1);

echo "=== CarbonTrack 全面测试 ===\n\n";

// 测试1: 基本PHP功能
echo "1. 测试基本PHP功能...\n";
echo "   PHP版本: " . PHP_VERSION . "\n";
echo "   当前时间: " . date('Y-m-d H:i:s') . "\n";
echo "   工作目录: " . getcwd() . "\n";
echo "   操作系统: " . PHP_OS . "\n";
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

// 测试4: 依赖配置
echo "4. 测试依赖配置...\n";
try {
    $dependencies = require __DIR__ . '/src/dependencies.php';
    $dependencies($container);
    echo "   ✓ 依赖配置加载成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 依赖配置加载失败: " . $e->getMessage() . "\n\n";
    echo "   错误详情:\n";
    echo "   " . $e->getTraceAsString() . "\n\n";
    exit(1);
}

// 测试5: Logger服务
echo "5. 测试Logger服务...\n";
try {
    $logger = $container->get(\Monolog\Logger::class);
    echo "   ✓ Logger服务获取成功: " . get_class($logger) . "\n";
    
    // 测试日志记录
    $logger->info('测试日志记录');
    echo "   ✓ 日志记录测试成功\n\n";
} catch (Exception $e) {
    echo "   ✗ Logger服务测试失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试6: Slim应用
echo "6. 测试Slim应用...\n";
try {
    \Slim\Factory\AppFactory::setContainer($container);
    $app = \Slim\Factory\AppFactory::create();
    echo "   ✓ Slim应用创建成功\n\n";
} catch (Exception $e) {
    echo "   ✗ Slim应用创建失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试7: 中间件
echo "7. 测试中间件...\n";
try {
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    echo "   ✓ 中间件添加成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 中间件添加失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试8: 调试路由
echo "8. 测试调试路由...\n";
try {
    $app->get('/debug', function ($request, $response) {
        $response->getBody()->write('Debug route working!');
        return $response;
    });
    echo "   ✓ 调试路由注册成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 调试路由注册失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试9: 项目路由
echo "9. 测试项目路由...\n";
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

// 测试10: 路由冲突检查
echo "10. 检查路由冲突...\n";
try {
    // 尝试获取路由收集器
    $routeCollector = $app->getRouteCollector();
    $routes = $routeCollector->getRoutes();
    echo "   ✓ 路由收集器访问成功\n";
    echo "   ✓ 总路由数量: " . count($routes) . "\n\n";
} catch (Exception $e) {
    echo "   ✗ 路由冲突检查失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

// 测试11: 错误中间件
echo "11. 测试错误中间件...\n";
try {
    $errorMiddleware = $app->addErrorMiddleware(true, true, true);
    echo "   ✓ 错误中间件添加成功\n\n";
} catch (Exception $e) {
    echo "   ✗ 错误中间件添加失败: " . $e->getMessage() . "\n\n";
    exit(1);
}

echo "🎉 所有测试通过！\n\n";
echo "现在可以启动应用程序了：\n";
echo "cd backend && php -S localhost:8080 -t public\n\n";
echo "可用的测试路径：\n";
echo "- / (健康检查)\n";
echo "- /debug (调试信息)\n";
echo "- /api/v1 (API根路径)\n";
echo "- /api/v1/auth/register (用户注册)\n";
echo "- /api/v1/users/me (获取当前用户)\n";
echo "- /api/v1/carbon-activities (获取碳活动列表)\n";
echo "- /api/v1/admin/users (管理员获取用户列表)\n";
