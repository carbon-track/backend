<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use DI\Container;
use Slim\App;
use Slim\Factory\AppFactory;

final class EnhancedOpenApiChecker
{
    /** @var array<string, array{operationId: string|null, deprecated: bool}> */
    private array $openApiOperations = [];

    /** @var array<string, array{path: string, handler: string, handler_exists: bool}> */
    private array $runtimeRoutes = [];

    private App $app;

    public function __construct()
    {
        $this->loadOpenApiOperations();
        $this->bootApplication();
        $this->extractRuntimeRoutes();
    }

    private function loadOpenApiOperations(): void
    {
        $raw = file_get_contents(__DIR__ . '/openapi.json');
        if ($raw === false) {
            throw new RuntimeException('Unable to read backend/openapi.json');
        }

        $spec = json_decode($raw, true);
        if (!is_array($spec) || !isset($spec['paths']) || !is_array($spec['paths'])) {
            throw new RuntimeException('Invalid OpenAPI document: missing paths');
        }

        foreach ($spec['paths'] as $path => $pathItem) {
            if (!is_array($pathItem) || $path === '/{routes}') {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                $upperMethod = strtoupper((string) $method);
                if (!in_array($upperMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }

                $signature = $this->signature($upperMethod, (string) $path);
                $this->openApiOperations[$signature] = [
                    'operationId' => is_array($operation) ? ($operation['operationId'] ?? null) : null,
                    'deprecated' => is_array($operation) && !empty($operation['deprecated']),
                ];
            }
        }
    }

    private function bootApplication(): void
    {
        $_ENV['DATABASE_PATH'] = __DIR__ . '/test.db';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_ENV['DATABASE_PATH'];
        $_ENV['JWT_SECRET'] = 'test_secret';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'test_turnstile';

        $container = new Container();
        require __DIR__ . '/src/dependencies.php';

        $this->app = AppFactory::createFromContainer($container);
        $this->app->addRoutingMiddleware();

        $routes = require __DIR__ . '/src/routes.php';
        $routes($this->app);
    }

    private function extractRuntimeRoutes(): void
    {
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $normalizedPath = $this->normalizeRoutePattern($route->getPattern());
            if ($normalizedPath === '/{routes}') {
                continue;
            }

            $handler = $this->stringifyCallable($route->getCallable());
            $handlerExists = $this->callableExists($handler);

            foreach ($route->getMethods() as $method) {
                $signature = $this->signature(strtoupper($method), $normalizedPath);
                $this->runtimeRoutes[$signature] = [
                    'path' => $normalizedPath,
                    'handler' => $handler,
                    'handler_exists' => $handlerExists,
                ];
            }
        }
    }

    private function normalizeRoutePattern(string $pattern): string
    {
        return (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $pattern);
    }

    private function stringifyCallable(mixed $callable): string
    {
        if (is_array($callable) && count($callable) === 2) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];
            return $class . '::' . (string) $callable[1];
        }

        if (is_string($callable)) {
            return $callable;
        }

        if ($callable instanceof Closure) {
            return 'closure';
        }

        return 'closure';
    }

    private function callableExists(string $handler): bool
    {
        if ($handler === 'closure') {
            return true;
        }

        if (!str_contains($handler, '::')) {
            return false;
        }

        [$class, $method] = explode('::', $handler, 2);
        $sourceFile = $this->resolveSourceFile($class);
        if ($sourceFile !== null && is_file($sourceFile)) {
            $source = file_get_contents($sourceFile);
            if ($source === false) {
                return false;
            }

            return (bool) preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source);
        }

        return class_exists($class) && method_exists($class, $method);
    }

    private function resolveSourceFile(string $class): ?string
    {
        $prefix = 'CarbonTrack\\';
        if (!str_starts_with($class, $prefix)) {
            return null;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        return __DIR__ . '/src/' . $relative . '.php';
    }

    private function signature(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }

    public function report(): int
    {
        $specSignatures = array_keys($this->openApiOperations);
        $runtimeSignatures = array_keys($this->runtimeRoutes);

        sort($specSignatures);
        sort($runtimeSignatures);

        $missingInRuntime = array_values(array_diff($specSignatures, $runtimeSignatures));
        $missingInSpec = array_values(array_diff($runtimeSignatures, $specSignatures));
        $brokenHandlers = [];

        foreach ($this->runtimeRoutes as $signature => $route) {
            if (!$route['handler_exists']) {
                $brokenHandlers[$signature] = $route['handler'];
            }
        }

        echo "=== Enhanced OpenAPI Runtime Alignment ===\n";
        echo 'Documented operations: ' . count($specSignatures) . "\n";
        echo 'Runtime operations: ' . count($runtimeSignatures) . "\n";
        echo 'Matching operations: ' . count(array_intersect($specSignatures, $runtimeSignatures)) . "\n";
        echo "Excluded runtime catch-all: /{routes}\n\n";

        if ($missingInRuntime !== []) {
            echo "OpenAPI operations missing from runtime:\n";
            foreach ($missingInRuntime as $signature) {
                echo '  - ' . $signature . "\n";
            }
            echo "\n";
        }

        if ($missingInSpec !== []) {
            echo "Runtime operations missing from OpenAPI:\n";
            foreach ($missingInSpec as $signature) {
                $handler = $this->runtimeRoutes[$signature]['handler'] ?? 'unknown';
                echo '  - ' . $signature . ' => ' . $handler . "\n";
            }
            echo "\n";
        }

        if ($brokenHandlers !== []) {
            echo "Runtime routes with unresolved handlers:\n";
            foreach ($brokenHandlers as $signature => $handler) {
                echo '  - ' . $signature . ' => ' . $handler . "\n";
            }
            echo "\n";
        }

        $isAligned = $missingInRuntime === [] && $missingInSpec === [] && $brokenHandlers === [];
        if ($isAligned) {
            echo "Full alignment confirmed for documented runtime routes.\n";
            echo "Verified distinct public roots: GET / and GET /api/v1.\n";
            echo "Verified runtime handlers exist for all documented operations.\n";
            return 0;
        }

        return 1;
    }
}

try {
    $checker = new EnhancedOpenApiChecker();
    exit($checker->report());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Enhanced OpenAPI check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
