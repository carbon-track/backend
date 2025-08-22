<?php

declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

echo "测试路由注册...\n";

// Load environment variables (with fallback)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    $_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
    $_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? 'true';
}

// Create Container and register dependencies
$container = new Container();
$dependencies = require __DIR__ . '/src/dependencies.php';
$dependencies($container);

// Set container to create App with on AppFactory and then create the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true
);

// Register routes
echo "注册路由...\n";
$routes = require __DIR__ . '/src/routes.php';
$routes($app);

// Add debug route
$app->get('/debug', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Debug route working',
        'routes' => 'Routes registered successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

echo "✓ 路由注册完成\n";

// Test route registration
echo "\n测试路由:\n";
echo "- 根路径 /: " . ($app->getRouteCollector()->getRoutes()['GET /'] ? '✓ 已注册' : '✗ 未注册') . "\n";
echo "- API路径 /api/v1: " . ($app->getRouteCollector()->getRoutes()['GET /api/v1'] ? '✓ 已注册' : '✗ 未注册') . "\n";
echo "- 调试路径 /debug: " . ($app->getRouteCollector()->getRoutes()['GET /debug'] ? '✓ 已注册' : '✗ 未注册') . "\n";

// List all registered routes
echo "\n所有已注册的路由:\n";
$routes = $app->getRouteCollector()->getRoutes();
foreach ($routes as $route) {
    $methods = implode(',', $route->getMethods());
    $pattern = $route->getPattern();
    echo "- {$methods} {$pattern}\n";
}

echo "\n🎉 路由测试完成！\n";
echo "现在可以测试应用程序了。\n";
