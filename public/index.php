<?php

// --- Error Handling & Environment Setup ---
// Prevent PHP warnings and notices from breaking the JSON response format.
// In production, these should be logged, not displayed.
ini_set('display_errors', '0');
error_reporting(E_ALL);

declare(strict_types=1);

use DI\Container;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use CarbonTrack\Middleware\CorsMiddleware;
use CarbonTrack\Middleware\LoggingMiddleware;
use CarbonTrack\Middleware\IdempotencyMiddleware;
use CarbonTrack\Services\DatabaseService;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables (with fallback)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // If .env file doesn't exist, set default values
    $_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
    $_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? 'true';
}

// Create Container and register dependencies before creating the app
$container = new Container();
$dependencies = require __DIR__ . '/../src/dependencies.php';
$dependencies($container);

// Set container to create App with on AppFactory and then create the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add CORS Middleware FIRST so it can short-circuit OPTIONS preflight before routing
$app->add(new CorsMiddleware());

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Logging Middleware - now Logger is available in container
try {
    // 直接从容器获取Monolog Logger实例，而不是通过接口
    $logger = $container->get(\Monolog\Logger::class);
    $app->add(new LoggingMiddleware($logger));
    // 添加幂等性中间件，保护敏感写操作
    $app->add(new IdempotencyMiddleware(
        $container->get(DatabaseService::class),
        $logger
    ));
} catch (\Exception $e) {
    // If Logger creation fails, log error and continue without logging middleware
    error_log('Failed to create LoggingMiddleware: ' . $e->getMessage());
}

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Add Error Middleware with default error handling
$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true
);

// Custom error handler for 404 errors
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function (Psr\Http\Message\ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'timestamp' => date('Y-m-d H:i:s')
        ]));
        
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'application/json');
    }
);

// Add a debug route to test if routing is working
$app->get('/debug', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Debug route working',
        'routes' => 'Routes registered successfully',
        'timestamp' => date('Y-m-d H:i:s')
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// Register routes
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

// Run App
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);

