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
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Support\ErrorResponseBuilder;
use CarbonTrack\Support\LogDiagnosticRequest;
use Slim\Middleware\ErrorMiddleware;
use Slim\Exception\HttpException;

if (!defined('CARBONTRACK_NO_EMIT')) {
    define('CARBONTRACK_NO_EMIT', false);
}

if (CARBONTRACK_NO_EMIT === false) {
    set_exception_handler(static function (Throwable $exception): void {
        $message = class_exists(DatabaseService::class, false)
            ? DatabaseService::exceptionMessage($exception)
            : $exception->getMessage();
        error_log(sprintf(
            '[CarbonTrack] Unhandled request failure %s (%s:%d) %s',
            get_class($exception),
            $exception->getFile(),
            $exception->getLine(),
            str_replace(["\r", "\n"], ' ', $message)
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . APP_JSON);
            echo '{"success":false,"error":"Internal server error"}';
        }
    });
}

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables (with fallback)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    // If .env file doesn't exist, Dotenv will throw; fall back to defaults below.
}

// Default-deny: when APP_ENV is not configured, treat the deploy as production so
// missing config never lands in the lax development branch (B-103/B-304).
$resolvedAppEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
$resolvedAppEnv = is_string($resolvedAppEnv) ? strtolower($resolvedAppEnv) : 'production';
$_ENV['APP_ENV'] = $resolvedAppEnv;
$_SERVER['APP_ENV'] = $resolvedAppEnv;
putenv('APP_ENV=' . $resolvedAppEnv);
$isProduction = $resolvedAppEnv === 'production';

$databaseExtension = ($_ENV['DB_CONNECTION'] ?? 'mysql') === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
if (!extension_loaded($databaseExtension)) {
    throw new RuntimeException(sprintf(
        'Enable PHP extension %s. PHP %s (%s) loaded %s.',
        $databaseExtension,
        PHP_VERSION,
        PHP_SAPI,
        php_ini_loaded_file() ?: 'no php.ini'
    ));
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
    !$isProduction,
    true,
    true
);

// 2. Routing Middleware - This must run before the app's routes are processed.
$app->addRoutingMiddleware();

// 3. Application-specific middleware
try {
    $logger = $container->get(\Monolog\Logger::class);
    $app->add(new IdempotencyMiddleware(
        $container->get(DatabaseService::class),
        $logger,
        $container->get(AuthService::class)
    ));
    $app->add(new LoggingMiddleware($logger));
} catch (\Exception $e) {
    error_log('Failed to create application middleware: ' . DatabaseService::exceptionMessage($e));
}

// 4. Body Parsing Middleware - Added after application middleware so, under
// Slim's LIFO execution order, parsed bodies are available before idempotency
// fingerprints are computed.
$app->addBodyParsingMiddleware();

// 5. CORS Middleware - Added last, so it executes first.
// This allows it to intercept preflight OPTIONS requests before the router even runs.
$app->add(new CorsMiddleware());

// Custom error handler for 404 errors
$errorMiddleware->setErrorHandler(
    Slim\Exception\HttpNotFoundException::class,
    function (Psr\Http\Message\ServerRequestInterface $request) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => 'Not Found',
            'message' => 'The requested resource was not found',
            'path' => LogDiagnosticRequest::path($request->getUri()->getPath()) ?? $request->getUri()->getPath(),
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
    ) use ($container, $resolvedAppEnv) {
        $diagnosticPath = LogDiagnosticRequest::path($request->getUri()->getPath());
        try {
            $els = $container->get(ErrorLogService::class);
            $els->logException($exception, $request);
        } catch (Throwable $e) {
            error_log($diagnosticPath === null
                ? 'Failed to persist unhandled exception: ' . DatabaseService::exceptionMessage($e)
                : 'Failed to persist unhandled diagnostic exception.');
        }

        try {
            if ($container->has(LoggerInterface::class)) {
                $container->get(LoggerInterface::class)->error('Unhandled application exception', $diagnosticPath === null ? [
                    'exception_type' => get_class($exception),
                    'error' => DatabaseService::exceptionMessage($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'environment' => $resolvedAppEnv,
                    'display_error_details' => $displayErrorDetails,
                    'log_errors' => $logErrors,
                    'log_error_details' => $logErrorDetails,
                ] : ['route' => $diagnosticPath, 'request_id' => LogDiagnosticRequest::requestId($request)]);
            }
        } catch (Throwable $loggerEx) {
            error_log($diagnosticPath === null
                ? 'Failed to log exception via logger: ' . DatabaseService::exceptionMessage($loggerEx)
                : 'Failed to log unhandled diagnostic exception.');
        }

        $response = new \Slim\Psr7\Response();
        $status = 500;

        // Derive an HTTP status code that works across Slim and other frameworks:
        // 1) Slim HttpException exposes the correct status via getCode()
        // 2) Some third-party exceptions expose getStatusCode()
        // 3) Fall back to Exception::getCode()
        $derivedStatus = null;
        if ($exception instanceof HttpException) {
            $derivedStatus = (int) $exception->getCode();
        } elseif (method_exists($exception, 'getStatusCode')) {
            try {
                $derivedStatus = (int) $exception->getStatusCode();
            } catch (\Throwable $statusEx) {
                $derivedStatus = null;
            }
        }

        if ($derivedStatus === null) {
            $derivedStatus = (int) $exception->getCode();
        }

        if ($derivedStatus >= 400 && $derivedStatus <= 599) {
            $status = $derivedStatus;
        }
        $payload = $diagnosticPath === null ? ErrorResponseBuilder::build($exception, $request, $resolvedAppEnv, $status) : [
            'error' => 'diagnostic_request_failed',
            'message' => 'The diagnostic request failed.',
            'request_id' => LogDiagnosticRequest::requestId($request),
        ];
        if ($diagnosticPath !== null) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', APP_JSON);
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

if (CARBONTRACK_NO_EMIT === true) {
    return [
        'app' => $app,
        'container' => $container,
        'errorMiddleware' => $errorMiddleware,
        'environment' => $resolvedAppEnv,
    ];
}

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Run App
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);

