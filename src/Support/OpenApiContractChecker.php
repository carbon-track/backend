<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

use Closure;
use DI\Container;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

final class OpenApiContractChecker
{
    /** @var list<string> */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    public function __construct(private string $backendRoot)
    {
        $this->backendRoot = rtrim($backendRoot, '/\\');
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $spec = $this->loadOpenApiDocument();
        $runtimeRoutes = $this->extractRuntimeRoutes();
        $adminAiConfig = $this->loadAdminAiConfig();

        return self::checkDocuments($spec, $runtimeRoutes, $adminAiConfig);
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,array{path:string,handler:string,handler_exists:bool}> $runtimeRoutes
     * @param array<string,mixed> $adminAiConfig
     * @return array<string,mixed>
     */
    public static function checkDocuments(array $spec, array $runtimeRoutes, array $adminAiConfig = []): array
    {
        $operations = self::extractOpenApiOperations($spec);
        $specSignatures = array_keys($operations);
        $runtimeSignatures = array_keys($runtimeRoutes);

        sort($specSignatures);
        sort($runtimeSignatures);

        $issues = self::emptyIssues();
        $issues['missing_in_runtime'] = array_values(array_diff($specSignatures, $runtimeSignatures));
        $issues['missing_in_spec'] = array_values(array_diff($runtimeSignatures, $specSignatures));

        foreach ($runtimeRoutes as $signature => $route) {
            if (!$route['handler_exists']) {
                $issues['broken_handlers'][$signature] = $route['handler'];
            }
        }

        $operationIdIndex = [];
        foreach ($operations as $signature => $operationInfo) {
            $path = $operationInfo['path'];
            $operation = $operationInfo['operation'];
            $pathItem = $operationInfo['path_item'];

            $operationId = self::stringValue($operation['operationId'] ?? null);
            if ($operationId === '') {
                $issues['missing_operation_id'][] = $signature;
            } else {
                $operationIdIndex[$operationId][] = $signature;
            }

            if (!isset($operation['tags']) || !is_array($operation['tags']) || self::nonEmptyStringList($operation['tags']) === []) {
                $issues['missing_tags'][] = $signature;
            }

            if (self::stringValue($operation['summary'] ?? null) === '') {
                $issues['missing_summary'][] = $signature;
            }

            if (self::stringValue($operation['description'] ?? null) === '') {
                $issues['missing_description'][] = $signature;
            }

            $parameterMismatch = self::findPathParameterMismatch($path, $pathItem, $operation);
            if ($parameterMismatch !== null) {
                $issues['path_parameter_mismatches'][$signature] = $parameterMismatch;
            }

            foreach (self::contentEntriesMissingSchema($operation['requestBody']['content'] ?? null) as $mediaType) {
                $issues['request_body_content_missing_schema'][] = $signature . ' ' . $mediaType;
            }

            foreach (($operation['responses'] ?? []) as $status => $response) {
                if (!is_array($response)) {
                    continue;
                }

                foreach (self::contentEntriesMissingSchema($response['content'] ?? null) as $mediaType) {
                    $issues['response_content_missing_schema'][] = $signature . ' response ' . (string) $status . ' ' . $mediaType;
                }
            }

            $responseCodes = array_keys(is_array($operation['responses'] ?? null) ? $operation['responses'] : []);
            if (!self::hasResponseCodeMatching($responseCodes, '/^2\d\d$/')) {
                $issues['missing_success_response'][] = $signature;
            }
            if (!self::hasResponseCodeMatching($responseCodes, '/^[45]\d\d$/') && !in_array('default', $responseCodes, true)) {
                $issues['missing_error_response'][] = $signature;
            }
        }

        foreach ($operationIdIndex as $operationId => $signatures) {
            if (count($signatures) > 1) {
                sort($signatures);
                $issues['duplicate_operation_ids'][$operationId] = $signatures;
            }
        }

        $issues['admin_ai_api_targets_missing_from_contract'] = self::findMissingAdminAiApiTargets(
            $adminAiConfig,
            $specSignatures,
            $runtimeSignatures
        );

        self::sortIssues($issues);

        return [
            'ok' => !self::hasIssues($issues),
            'summary' => [
                'documented_operations' => count($specSignatures),
                'runtime_operations' => count($runtimeSignatures),
                'matching_operations' => count(array_intersect($specSignatures, $runtimeSignatures)),
                'excluded_runtime_catch_all' => '/{routes}',
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public static function renderReport(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $lines = [
            '=== Enhanced OpenAPI Contract Gate ===',
            'Documented operations: ' . (string) ($summary['documented_operations'] ?? 0),
            'Runtime operations: ' . (string) ($summary['runtime_operations'] ?? 0),
            'Matching operations: ' . (string) ($summary['matching_operations'] ?? 0),
            'Excluded runtime catch-all: ' . (string) ($summary['excluded_runtime_catch_all'] ?? '/{routes}'),
            '',
        ];

        foreach ($issues as $issueName => $items) {
            if ($items === [] || $items === null) {
                continue;
            }

            $lines[] = self::issueTitle((string) $issueName) . ':';
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $lines[] = '  - ' . (is_string($key) ? $key . ': ' : '') . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (is_string($key) && !is_int($key)) {
                    $lines[] = '  - ' . $key . ' => ' . (string) $value;
                } else {
                    $lines[] = '  - ' . (string) $value;
                }
            }
            $lines[] = '';
        }

        if (!empty($result['ok'])) {
            $lines[] = 'Full OpenAPI contract gate passed.';
            $lines[] = 'Verified runtime route alignment, handler existence, operation metadata, schemas, responses, path parameters, and admin AI API targets.';
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadOpenApiDocument(): array
    {
        $path = $this->backendRoot . '/openapi.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Unable to read backend/openapi.json');
        }

        $spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($spec) || !isset($spec['paths']) || !is_array($spec['paths'])) {
            throw new RuntimeException('Invalid OpenAPI document: missing paths');
        }

        return $spec;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadAdminAiConfig(): array
    {
        $path = $this->backendRoot . '/config/admin_ai_commands.php';
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string,array{path:string,handler:string,handler_exists:bool}>
     */
    private function extractRuntimeRoutes(): array
    {
        $app = $this->bootApplication();
        $runtimeRoutes = [];

        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $normalizedPath = $this->normalizeRoutePattern($route->getPattern());
            if ($normalizedPath === '/{routes}') {
                continue;
            }

            $handler = $this->stringifyCallable($route->getCallable());
            $handlerExists = $this->callableExists($handler);

            foreach ($route->getMethods() as $method) {
                $signature = self::signature(strtoupper($method), $normalizedPath);
                $runtimeRoutes[$signature] = [
                    'path' => $normalizedPath,
                    'handler' => $handler,
                    'handler_exists' => $handlerExists,
                ];
            }
        }

        ksort($runtimeRoutes);

        return $runtimeRoutes;
    }

    private function bootApplication(): App
    {
        static $app = null;
        if ($app instanceof App) {
            return $app;
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'carbontrack_openapi_contract_');
        if ($databasePath === false) {
            throw new RuntimeException('Unable to create temporary OpenAPI contract database path');
        }
        register_shutdown_function(fn (): bool => $this->removeTemporaryDatabaseFiles($databasePath));

        $app = $this->withTemporaryEnv([
            'DATABASE_PATH' => $databasePath,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $databasePath,
            'JWT_SECRET' => 'test_secret',
            'TURNSTILE_SECRET_KEY' => 'test_turnstile',
        ], function (): App {
            $container = new Container();
            require $this->backendRoot . '/src/dependencies.php';

            $app = AppFactory::createFromContainer($container);
            $app->addRoutingMiddleware();

            $routes = require $this->backendRoot . '/src/routes.php';
            $routes($app);

            return $app;
        });

        return $app;
    }

    private function normalizeRoutePattern(string $pattern): string
    {
        return (string) preg_replace('/\{([^}:]+)(?::[^}]*)?\}/', '{$1}', $pattern);
    }

    private function removeTemporaryDatabaseFiles(string $databasePath): bool
    {
        $tempRoot = realpath(sys_get_temp_dir());
        if ($tempRoot === false) {
            return false;
        }

        $filesystem = new Filesystem();
        $allowedPaths = [];
        foreach ([$databasePath, $databasePath . '-journal', $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            $allowedPaths[$path] = true;
        }

        foreach (array_keys($allowedPaths) as $path) {
            if (!isset($allowedPaths[$path])) {
                continue;
            }

            $realPath = realpath($path);
            if ($realPath === false || !is_file($realPath)) {
                continue;
            }

            $isTempChild = str_starts_with($realPath, $tempRoot . DIRECTORY_SEPARATOR);
            if (!$isTempChild || $realPath !== $path) {
                continue;
            }

            try {
                $filesystem->remove($realPath);
            } catch (IOExceptionInterface) {
                return false;
            }
        }

        return true;
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

        $classMethod = $this->splitClassMethodHandler($handler);
        if ($classMethod === null) {
            return false;
        }

        [$class, $method] = $classMethod;

        return class_exists($class) && method_exists($class, $method);
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function splitClassMethodHandler(string $handler): ?array
    {
        $delimiter = str_contains($handler, '::') ? '::' : (str_contains($handler, ':') ? ':' : null);
        if ($delimiter === null) {
            return null;
        }

        [$class, $method] = explode($delimiter, $handler, 2);
        $class = trim($class);
        $method = trim($method);
        if ($class === '' || $method === '') {
            return null;
        }

        return [$class, $method];
    }

    /**
     * @template T
     * @param array<string,string> $values
     * @param callable():T $callback
     * @return T
     */
    private function withTemporaryEnv(array $values, callable $callback): mixed
    {
        $previousEnv = [];
        $previousServer = [];
        $previousProcess = [];
        $existedEnv = [];
        $existedServer = [];
        $existedProcess = [];

        foreach ($values as $key => $value) {
            $existedEnv[$key] = array_key_exists($key, $_ENV);
            $previousEnv[$key] = $_ENV[$key] ?? null;
            $existedServer[$key] = array_key_exists($key, $_SERVER);
            $previousServer[$key] = $_SERVER[$key] ?? null;
            $processValue = getenv($key);
            $existedProcess[$key] = $processValue !== false;
            $previousProcess[$key] = $processValue;

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($values as $key => $_) {
                if ($existedEnv[$key]) {
                    $_ENV[$key] = $previousEnv[$key];
                } else {
                    unset($_ENV[$key]);
                }

                if ($existedServer[$key]) {
                    $_SERVER[$key] = $previousServer[$key];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($existedProcess[$key]) {
                    putenv($key . '=' . $previousProcess[$key]);
                } else {
                    putenv($key);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,array{path:string,method:string,operation:array<string,mixed>,path_item:array<string,mixed>}>
     */
    private static function extractOpenApiOperations(array $spec): array
    {
        $operations = [];
        foreach (($spec['paths'] ?? []) as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem) || $path === '/{routes}') {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                $upperMethod = strtoupper((string) $method);
                if (!in_array($upperMethod, self::HTTP_METHODS, true) || !is_array($operation)) {
                    continue;
                }

                $operations[self::signature($upperMethod, $path)] = [
                    'path' => $path,
                    'method' => $upperMethod,
                    'operation' => $operation,
                    'path_item' => $pathItem,
                ];
            }
        }

        ksort($operations);

        return $operations;
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyIssues(): array
    {
        return [
            'missing_in_runtime' => [],
            'missing_in_spec' => [],
            'broken_handlers' => [],
            'missing_operation_id' => [],
            'duplicate_operation_ids' => [],
            'missing_tags' => [],
            'missing_summary' => [],
            'missing_description' => [],
            'path_parameter_mismatches' => [],
            'request_body_content_missing_schema' => [],
            'response_content_missing_schema' => [],
            'missing_success_response' => [],
            'missing_error_response' => [],
            'admin_ai_api_targets_missing_from_contract' => [],
        ];
    }

    /**
     * @param array<string,mixed> $pathItem
     * @param array<string,mixed> $operation
     * @return array{missing:list<string>,not_required:list<string>,extra:list<string>}|null
     */
    private static function findPathParameterMismatch(string $path, array $pathItem, array $operation): ?array
    {
        $pathParameters = self::pathParameterNames($path);
        $declaredParameters = [];
        $notRequired = [];

        $parameters = [];
        if (isset($pathItem['parameters']) && is_array($pathItem['parameters'])) {
            $parameters = array_merge($parameters, $pathItem['parameters']);
        }
        if (isset($operation['parameters']) && is_array($operation['parameters'])) {
            $parameters = array_merge($parameters, $operation['parameters']);
        }

        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || ($parameter['in'] ?? null) !== 'path' || !is_string($parameter['name'] ?? null)) {
                continue;
            }

            $name = $parameter['name'];
            $declaredParameters[] = $name;
            if (($parameter['required'] ?? null) !== true) {
                $notRequired[] = $name;
            }
        }

        $declaredParameters = array_values(array_unique($declaredParameters));
        sort($declaredParameters);
        sort($pathParameters);

        $missing = array_values(array_diff($pathParameters, $declaredParameters));
        $extra = array_values(array_diff($declaredParameters, $pathParameters));
        $notRequired = array_values(array_intersect(array_unique($notRequired), $pathParameters));
        sort($notRequired);

        if ($missing === [] && $extra === [] && $notRequired === []) {
            return null;
        }

        return [
            'missing' => $missing,
            'not_required' => $notRequired,
            'extra' => $extra,
        ];
    }

    /**
     * @return list<string>
     */
    private static function pathParameterNames(string $path): array
    {
        preg_match_all('/\{([^}:]+)(?::[^}]*)?\}/', $path, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function contentEntriesMissingSchema(mixed $content): array
    {
        if (!is_array($content)) {
            return [];
        }

        $missing = [];
        foreach ($content as $mediaType => $mediaDefinition) {
            if (!is_array($mediaDefinition) || !isset($mediaDefinition['schema']) || !is_array($mediaDefinition['schema'])) {
                $missing[] = (string) $mediaType;
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * @param list<string> $responseCodes
     */
    private static function hasResponseCodeMatching(array $responseCodes, string $pattern): bool
    {
        foreach ($responseCodes as $code) {
            if (preg_match($pattern, (string) $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $adminAiConfig
     * @param list<string> $specSignatures
     * @param list<string> $runtimeSignatures
     * @return array<string,string>
     */
    private static function findMissingAdminAiApiTargets(array $adminAiConfig, array $specSignatures, array $runtimeSignatures): array
    {
        $specSet = array_flip($specSignatures);
        $runtimeSet = array_flip($runtimeSignatures);
        $missing = [];
        $actions = $adminAiConfig['managementActions'] ?? [];
        if (!is_array($actions)) {
            return [];
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $name = self::stringValue($action['name'] ?? null);
            $api = $action['api'] ?? null;
            if ($name === '' || !is_array($api)) {
                continue;
            }

            $method = self::stringValue($api['method'] ?? null);
            $path = self::stringValue($api['path'] ?? null);
            if ($method === '' || $path === '') {
                continue;
            }

            $signature = self::signature(strtoupper($method), $path);
            if (!isset($specSet[$signature]) || !isset($runtimeSet[$signature])) {
                $missing[$name] = $signature;
            }
        }

        ksort($missing);

        return $missing;
    }

    private static function signature(string $method, string $path): string
    {
        return $method . ' ' . $path;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<int,mixed> $items
     * @return list<string>
     */
    private static function nonEmptyStringList(array $items): array
    {
        return array_values(array_filter($items, static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    /**
     * @param array<string,mixed> $issues
     */
    private static function hasIssues(array $issues): bool
    {
        foreach ($issues as $items) {
            if ($items !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $issues
     */
    private static function sortIssues(array &$issues): void
    {
        foreach ($issues as &$items) {
            if (is_array($items)) {
                if (array_is_list($items)) {
                    sort($items);
                } else {
                    ksort($items);
                }
            }
        }
    }

    private static function issueTitle(string $issueName): string
    {
        return ucwords(str_replace('_', ' ', $issueName));
    }
}
