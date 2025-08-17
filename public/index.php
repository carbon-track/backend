<?php

declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use CarbonTrack\Middleware\CorsMiddleware;
use CarbonTrack\Middleware\LoggingMiddleware;

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

// Create Container
$container = new Container();

// Set container to create App with on AppFactory
AppFactory::setContainer($container);

// Create App
$app = AppFactory::create();

// Register dependencies FIRST
require __DIR__ . '/../src/dependencies.php';

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add CORS Middleware
$app->add(new CorsMiddleware());

// Add Logging Middleware - now Logger is available in container
try {
    $logger = $container->get(\Monolog\Logger::class);
    $app->add(new LoggingMiddleware($logger));
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

// Register routes
require __DIR__ . '/../src/routes.php';

// Run App
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);

