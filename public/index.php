<?php
declare(strict_types=1);

// Small shared constants
const APP_DATE_FMT = 'Y-m-d H:i:s';
const APP_JSON = 'application/json';

// --- Error Handling & Environment Setup ---
// Prevent PHP warnings and notices from breaking the JSON response format.
// In production, these should be logged, not displayed.
ini_set('display_errors', '0');
error_reporting(E_ALL);

use DI\Container;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;
use CarbonTrack\Middleware\CorsMiddleware;
use CarbonTrack\Middleware\LoggingMiddleware;
use CarbonTrack\Middleware\IdempotencyMiddleware;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Services\ErrorLogService;
use Slim\Middleware\ErrorMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

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
$dependencies = require_once __DIR__ . '/../src/dependencies.php';
$dependencies($container);

// Set container to create App with on AppFactory and then create the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// --- Middleware Registration (Order is important: LIFO - Last In, First Out) ---
// The last middleware added is the first to be executed.

// 1. Error Middleware - Added first, so it executes last, catching all exceptions.
$errorMiddleware = $app->addErrorMiddleware(
    (bool) ($_ENV['APP_DEBUG'] ?? false),
    true,
    true
);

// 2. Routing Middleware - This must run before the app's routes are processed.
$app->addRoutingMiddleware();

// 3. Body Parsing Middleware
$app->addBodyParsingMiddleware();

// 4. Application-specific middleware
try {
    $logger = $container->get(\Monolog\Logger::class);
    $app->add(new LoggingMiddleware($logger));
    $app->add(new IdempotencyMiddleware(
        $container->get(DatabaseService::class),
        $logger
    ));
} catch (\Exception $e) {
    error_log('Failed to create application middleware: ' . $e->getMessage());
}

// 5. CORS Middleware - Added last, so it executes first.
// This allows it to intercept preflight OPTIONS requests before the router even runs.
$app->add(new CorsMiddleware());

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Custom error handler for 404 errors
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function (Psr\Http\Message\ServerRequestInterface $request) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'path' => $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'timestamp' => date(APP_DATE_FMT)
        ]));
        
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', APP_JSON);
    }
);

// Default error handler to ensure all unhandled exceptions are persisted to DB
$errorMiddleware->setDefaultErrorHandler(
    function (
        Psr\Http\Message\ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($container) {
        try {
            $els = $container->get(ErrorLogService::class);
            $els->logException($exception, $request);
        } catch (Throwable $e) {
            error_log('Failed to persist unhandled exception: ' . $e->getMessage());
        }

        $response = new \Slim\Psr7\Response();
        $payload = [
            'success' => false,
            'error' => 'Server Error',
            'message' => $displayErrorDetails ? $exception->getMessage() : 'An internal error has occurred',
            'timestamp' => date(APP_DATE_FMT),
            'logged' => $logErrors,
            'log_details' => $logErrorDetails
        ];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', APP_JSON);
    }
);

// Add a debug route to test if routing is working
$app->get('/debug', function ($request, $response) {
    $response->getBody()->write(json_encode([
        'success' => true,
        'message' => 'Debug route working',
        'routes' => 'Routes registered successfully',
        'path' => $request->getUri()->getPath(),
        'method' => $request->getMethod(),
        'timestamp' => date(APP_DATE_FMT)
    ]));
    return $response->withHeader('Content-Type', APP_JSON);
});

// Register routes
$routes = require_once __DIR__ . '/../src/routes.php';
$routes($app);

// Run App
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);

