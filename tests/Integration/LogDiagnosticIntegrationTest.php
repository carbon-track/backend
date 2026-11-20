<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Middleware\AdminMiddleware;
use CarbonTrack\Middleware\AuthMiddleware;
use CarbonTrack\Middleware\RequestLoggingMiddleware;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LogDiagnostics\DiagnosticAccess;
use CarbonTrack\Services\LogDiagnostics\DiagnosticConfig;
use CarbonTrack\Services\LogDiagnostics\DiagnosticOAuth;
use CarbonTrack\Services\LogDiagnostics\DiagnosticProblem;
use CarbonTrack\Services\SystemLogService;
use CarbonTrack\Support\Uuid;
use DateTimeZone;
use DI\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class LogDiagnosticIntegrationTest extends TestCase
{
    private const REST = DiagnosticConfig::API;
    private const ADMIN = DiagnosticConfig::ADMIN;
    private const API_ORIGIN = 'https://api.diagnostics.test';
    private const FRONTEND_ORIGIN = 'https://admin.diagnostics.test';
    private const CLIENT = 'diagnostics-fixture-client';
    private const CALLBACK = 'https://agent.diagnostics.test/callback';

    private App $app;
    private Connection $db;
    private Container $container;
    private DiagnosticConfig $config;
    private array $environment;
    private array $serverState;
    private ?PDO $mysql = null;
    private ?string $testDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->environment = $_ENV;
        $this->serverState = $_SERVER;
        $_ENV['ALLOW_TEST_AUTH_FALLBACK'] = 'false';
        $_ENV['DISABLE_AUDIT_LOG_WRITES'] = 'false';
        $_ENV['DISABLE_SYSTEM_LOG_WRITES'] = 'false';
        $_ENV['DISABLE_ERROR_LOG_WRITES'] = 'false';
        $this->db = $this->connection();
        $this->installSchema();
        $this->db->table('users')->insert([
            ['id' => 1, 'uuid' => Uuid::generateV4(), 'is_admin' => 1, 'status' => 'active'],
            ['id' => 2, 'uuid' => Uuid::generateV4(), 'is_admin' => 0, 'status' => 'active'],
        ]);
        $utc = new DateTimeZone('UTC');
        $this->config = new DiagnosticConfig(true, self::API_ORIGIN, self::FRONTEND_ORIGIN, str_repeat('k', 32), $utc, $utc,
            requestsPerMinute: 100, maxConcurrent: 2, oauthClients: [
                ['id' => self::CLIENT, 'name' => 'Fixture connector', 'redirect_uris' => [self::CALLBACK], 'auth_method' => 'none'],
            ]);
        $this->container = new Container();
        $init = require __DIR__ . '/../../src/dependencies.php';
        $init($this->container);
        $this->container->set(Connection::class, $this->db);
        $this->container->set(DiagnosticConfig::class, $this->config);

        $logger = new Logger('diagnostic-integration');
        $audit = new AuditLogService($this->db, $logger);
        $errors = new ErrorLogService($this->db, $logger);
        $this->container->set(AuditLogService::class, $audit);
        $this->container->set(ErrorLogService::class, $errors);

        $auth = $this->createMock(AuthService::class);
        $actor = static fn (int $id): array => ['id' => $id, 'is_admin' => $id === 1, 'role' => $id === 1 ? 'admin' : 'user'];
        $auth->method('getCurrentUser')->willReturnCallback(static fn ($r): ?array => match ($r->getHeaderLine('Authorization')) {
            'Bearer fixture-admin' => $actor(1),
            'Bearer fixture-user' => $actor(2),
            default => null,
        });
        $auth->method('validateToken')->willReturnCallback(static function (string $token) use ($actor): array {
            $id = match ($token) {
                'fixture-admin' => 1,
                'fixture-user' => 2,
                default => throw new \RuntimeException('Invalid fixture token'),
            };
            return ['user_id' => $id, 'user' => $actor($id), 'email' => null, 'role' => $actor($id)['role']];
        });
        $auth->method('isAdminUser')->willReturnCallback(static fn (array $user): bool => $user['is_admin']);
        $this->container->set(AuthMiddleware::class, new AuthMiddleware($auth, $audit));
        $this->container->set(AdminMiddleware::class, new AdminMiddleware($auth, $errors));
        $this->container->set(RequestLoggingMiddleware::class, new RequestLoggingMiddleware(
            new SystemLogService($this->db, $logger), $auth, $logger
        ));

        $this->app = new App(new ResponseFactory(), $this->container);
        $this->app->addRoutingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);
        $this->app->addBodyParsingMiddleware();
        $routes = require __DIR__ . '/../../src/routes.php';
        $routes($this->app);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        $_SERVER = $this->serverState;
        if ($this->mysql !== null && $this->testDatabase !== null) {
            $this->mysql->exec('DROP DATABASE ' . $this->testDatabase);
        }
        parent::tearDown();
    }

    public function testRestCredentialIsOneTimeScopedAndRevoked(): void
    {
        self::assertSame(403, $this->http('POST', self::ADMIN . '/sessions', ['issue_rest_token' => true], 'fixture-user')->getStatusCode());
        $grant = $this->grant(['system']);
        $id = $grant['session']['id'];
        $token = $grant['issued']['access_token'];
        $stored = $this->db->table('log_diagnostic_credentials')->first();
        self::assertSame(hash('sha256', $token), $stored->token_hash);
        $detail = $this->http('GET', self::ADMIN . '/sessions/' . $id, bearer: 'fixture-admin');
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringNotContainsString($token, (string) $detail->getBody());
        self::assertSame(401, $this->http('POST', self::REST . '/summary', [], 'fixture-admin')->getStatusCode());
        self::assertSame(401, $this->http('GET', self::ADMIN . '/sessions', bearer: $token)->getStatusCode());
        self::assertSame(403, $this->http('POST', self::REST . '/summary', ['types' => ['llm']], $token)->getStatusCode());
        self::assertSame(403, $this->http('POST', self::REST . '/summary', [
            'from' => DiagnosticConfig::iso(time() - 86400),
        ], $token)->getStatusCode());
        self::assertSame(200, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());

        $revoke = self::ADMIN . '/sessions/' . $id . '/credentials/' . $grant['issued']['credential_id'] . '/revoke';
        self::assertSame(200, $this->http('POST', $revoke, bearer: 'fixture-admin')->getStatusCode());
        self::assertSame(200, $this->http('POST', $revoke, bearer: 'fixture-admin')->getStatusCode());
        self::assertSame(401, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());

        $history = $this->json($this->http('GET', self::ADMIN . '/sessions/' . $id . '/access-audits', bearer: 'fixture-admin'));
        $queries = array_values(array_filter($history['items'], static fn ($event): bool => $event['action'] === 'log_diagnostic_query'));
        self::assertCount(4, $queries);
        self::assertSame(401, $queries[0]['response_code']);
        self::assertGreaterThan(0, $queries[1]['details']['response_bytes']);
        self::assertStringNotContainsString($token, json_encode($history, JSON_THROW_ON_ERROR));
    }

    public function testEnabledSettingsRequireAnOperationAudit(): void
    {
        $path = self::ADMIN . '/settings';
        $settings = $this->http('GET', $path, bearer: 'fixture-admin');
        self::assertSame(200, $settings->getStatusCode());
        self::assertSame(self::API_ORIGIN . self::REST, $this->json($settings)['rest_url']);
        $audit = $this->db->table('audit_logs')
            ->where('action', 'log_diagnostic_settings_viewed')
            ->where('request_id', $settings->getHeaderLine('X-Request-ID'))
            ->sole();
        self::assertSame('log_diagnostics', $audit->operation_category);
        self::assertSame(1, (int) $audit->user_id);
        self::assertSame($path, $audit->endpoint);
        self::assertSame(200, (int) $audit->response_code);

        $this->db->getSchemaBuilder()->drop('audit_logs');
        $settings = $this->http('GET', $path, bearer: 'fixture-admin');
        self::assertSame(503, $settings->getStatusCode());
        self::assertArrayNotHasKey('rest_url', $this->json($settings));
    }

    public function testDisabledDeploymentOffersNoDiagnosticDataOrDiscovery(): void
    {
        $utc = new DateTimeZone('UTC');
        $this->container->set(DiagnosticConfig::class, new DiagnosticConfig(
            false, self::API_ORIGIN, self::FRONTEND_ORIGIN, '', $utc, $utc
        ));
        $settings = $this->http('GET', self::ADMIN . '/settings', bearer: 'fixture-admin');
        self::assertSame(['enabled' => false], $this->json($settings));
        self::assertSame(404, $this->http('GET', DiagnosticConfig::RESOURCE_METADATA)->getStatusCode());
        self::assertSame(404, $this->http('POST', self::REST . '/summary', [])->getStatusCode());
    }

    public function testFixedWindowCrossTypeProjectionAndCursorBinding(): void
    {
        $timestamp = gmdate('Y-m-d H:i:s', time() - 60);
        $requestId = Uuid::generateV4();
        $this->db->table('system_logs')->insert([
            'request_id' => $requestId, 'created_at' => $timestamp, 'status_code' => 503, 'method' => 'GET',
            'path' => '/api/v1/users/me?token=secret-request-value', 'request_body' => 'secret-request-value',
            'response_body' => 'secret-response-value',
        ]);
        $this->db->table('audit_logs')->insert([
            ['request_id' => $requestId, 'created_at' => $timestamp, 'action' => 'user_view', 'operation_category' => null, 'old_data' => 'audit-private-body'],
            ['request_id' => $requestId, 'created_at' => $timestamp, 'action' => 'log_diagnostic_query', 'operation_category' => 'log_diagnostics', 'old_data' => 'prior-diagnostic-access'],
        ]);
        $this->db->table('error_logs')->insert([
            'request_id' => $requestId, 'error_time' => $timestamp, 'error_type' => 'NetworkTimeout',
            'error_message' => 'Upstream timeout: password=credential-material email=person@example.test',
            'error_file' => 'E:/srv/app/src/Controllers/UpstreamController.php', 'error_line' => 41,
            'client_post' => 'private-request',
        ]);
        $this->db->table('error_logs')->insert([
            'request_id' => $requestId, 'error_time' => gmdate('Y-m-d H:i:s', time() - 86400),
            'error_type' => 'OlderError', 'error_message' => 'Upstream timeout',
        ]);
        $this->db->table('llm_logs')->insert([
            'request_id' => $requestId, 'created_at' => $timestamp, 'model' => 'gpt-test', 'source' => 'admin_ai',
            'status' => 'success', 'prompt_tokens' => 42, 'prompt' => 'private-llm-prompt',
            'response_raw' => 'private-llm-output', 'error_message' => 'private-provider-error',
        ]);
        $grant = $this->grant(['system', 'error', 'audit']);
        $token = $grant['issued']['access_token'];
        $response = $this->http('POST', self::REST . '/summary', [], $token);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $summary = $this->json($response);
        self::assertSame(['audit' => 1, 'error' => 1, 'system' => 1], $summary['counts_by_type']);
        self::assertSame('NetworkTimeout', $summary['top_errors'][0]['error_type']);
        self::assertSame('Upstream timeout: [REDACTED] [REDACTED]', $summary['top_errors'][0]['example']['summary']);
        self::assertSame('src/Controllers/UpstreamController.php', $summary['top_errors'][0]['example']['location']);

        $query = ['request_id' => $requestId, 'limit' => 1];
        $result = $this->json($this->http('POST', self::REST . '/context', $query, $token));
        self::assertSame('audit', $result['items'][0]['type']);
        self::assertTrue($result['has_more']);
        self::assertNotEmpty($result['next_cursor']);
        $later = $this->json($this->http('POST', self::REST . '/context', $query + ['cursor' => $result['next_cursor']], $token));
        self::assertSame('error', $later['items'][0]['type']);
        $last = $this->json($this->http('POST', self::REST . '/context', $query + ['cursor' => $later['next_cursor']], $token));
        self::assertSame('system', $last['items'][0]['type']);
        self::assertSame('/api/v1/users/me', $last['items'][0]['route']);
        self::assertFalse($last['has_more']);
        self::assertStringNotContainsString('secret-response-value', json_encode($last, JSON_THROW_ON_ERROR));
        self::assertSame(400, $this->http('POST', self::REST . '/context', [
            'request_id' => Uuid::generateV4(), 'cursor' => $result['next_cursor'],
        ], $token)->getStatusCode());
        self::assertSame([], $this->json($this->http('POST', self::REST . '/search', [
            'type' => 'error', 'q' => 'credential-material',
        ], $token))['items']);
        $outOfWindow = $this->db->table('error_logs')->where('error_type', 'OlderError')->value('id');
        $missing = $this->json($this->http('POST', self::REST . '/context', ['type' => 'error', 'id' => (int) $outOfWindow], $token));
        self::assertSame('not_found', $missing['correlation_status']);
        self::assertSame([], $missing['items']);
        $llmToken = $this->grant(['llm'])['issued']['access_token'];
        $results = $this->http('POST', self::REST . '/search', ['type' => 'llm', 'model' => 'gpt-test'], $llmToken);
        self::assertSame(200, $results->getStatusCode());
        $metadata = $this->json($results)['items'][0];
        self::assertSame(42, $metadata['prompt_tokens']);
        foreach (['private-llm-prompt', 'private-llm-output', 'private-provider-error'] as $private) {
            self::assertStringNotContainsString($private, (string) $results->getBody());
        }

        $historical = $this->db->table('error_logs')->insertGetId([
            'error_time' => $timestamp, 'error_type' => 'LegacyError', 'error_message' => 'Upstream timeout',
        ]);
        $anchor = $this->json($this->http('POST', self::REST . '/context', ['type' => 'error', 'id' => $historical], $token));
        self::assertSame('unavailable', $anchor['correlation_status']);
        self::assertSame($historical, $anchor['items'][0]['id']);
    }

    public function testPaginationPreservesLongSummariesPastFiveMebibytes(): void
    {
        $message = 'Upstream service timeout.' . str_repeat(' Retry scheduled.', 3500);
        $timestamp = gmdate('Y-m-d H:i:s', time() - 60);
        for ($index = 0; $index < 110; $index++) {
            $this->db->table('error_logs')->insert(['error_time' => $timestamp, 'error_type' => 'Timeout', 'error_message' => $message]);
        }
        $grant = $this->grant(['error']);
        $token = $grant['issued']['access_token'];
        $cursor = null;
        $seen = [];
        $totalBytes = 0;
        do {
            $result = $this->http('POST', self::REST . '/search', ['type' => 'error'] + ($cursor ? ['cursor' => $cursor] : []), $token);
            self::assertSame(200, $result->getStatusCode(), substr((string) $result->getBody(), 0, 160));
            $totalBytes += strlen((string) $result->getBody());
            $page = $this->json($result);
            foreach ($page['items'] as $item) {
                self::assertSame(strlen($message), strlen($item['summary']));
                $seen[] = $item['id'];
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        self::assertCount(110, array_unique($seen));
        self::assertGreaterThan(5 * 1024 * 1024, $totalBytes);
        $detail = $this->json($this->http('GET', self::ADMIN . '/sessions/' . $grant['session']['id'], bearer: 'fixture-admin'));
        self::assertSame(3, $detail['query_count']);
        self::assertSame($totalBytes, $detail['result_bytes']);
    }

    public function testOAuthBrowserHandoffPkceAndBothMcpLifecycles(): void
    {
        $grant = $this->grant(['system']);
        $resource = $this->json($this->http('GET', DiagnosticConfig::RESOURCE_METADATA));
        $issuer = $this->json($this->http('GET', DiagnosticConfig::ISSUER_METADATA));
        self::assertSame($this->config->resource(), $resource['resource']);
        self::assertSame([$this->config->issuer()], $resource['authorization_servers']);
        self::assertSame(['S256'], $issuer['code_challenge_methods_supported']);
        self::assertSame(['RS256', 'ES256'], $issuer['token_endpoint_auth_signing_alg_values_supported']);
        self::assertTrue($issuer['authorization_response_iss_parameter_supported']);
        $verifier = DiagnosticConfig::secret();
        $params = [
            'client_id' => self::CLIENT, 'redirect_uri' => self::CALLBACK, 'resource' => $this->config->resource(),
            'state' => 'fixture-state', 'response_type' => 'code', 'code_challenge_method' => 'S256',
            'code_challenge' => DiagnosticConfig::base64Url(hash('sha256', $verifier, true)),
        ];
        $authorizePath = self::REST . '/oauth/authorize';
        $wrongRedirect = $this->http('GET', $authorizePath, query: array_replace($params, [
            'redirect_uri' => 'https://wrong.diagnostics.test/callback',
        ]));
        self::assertSame(400, $wrongRedirect->getStatusCode());
        self::assertFalse($wrongRedirect->hasHeader('Location'));

        $auth = $this->http('GET', $authorizePath, query: $params);
        self::assertSame(303, $auth->getStatusCode(), (string) $auth->getBody());
        self::assertStringStartsWith(self::FRONTEND_ORIGIN . '/admin/system-logs/diagnostic-consent/', $auth->getHeaderLine('Location'));
        self::assertStringContainsString('Secure; HttpOnly; SameSite=Lax', $auth->getHeaderLine('Set-Cookie'));
        $browser = explode('=', explode(';', $auth->getHeaderLine('Set-Cookie'), 2)[0], 2)[1];
        $id = basename($auth->getHeaderLine('Location'));
        $requestPath = self::ADMIN . '/oauth-requests/' . $id;
        $description = $this->http('GET', $requestPath, bearer: 'fixture-admin');
        self::assertSame('Fixture connector', $this->json($description)['client_name']);
        foreach ([
            [$auth, $authorizePath],
            [$description, self::ADMIN . '/oauth-requests/{id}'],
        ] as [$response, $safePath]) {
            $meta = $this->db->table('system_logs')->where('request_id', $response->getHeaderLine('X-Request-ID'))
                ->value('server_meta');
            self::assertSame($safePath, json_decode($meta, true, 512, JSON_THROW_ON_ERROR)['_summary']['uri']);
        }
        $decision = $this->json($this->http('POST', $requestPath . '/decision', [
            'approve' => true, 'session_id' => $grant['session']['id'],
        ], 'fixture-admin', headers: ['Origin' => self::FRONTEND_ORIGIN]));
        $ticket = explode('=', parse_url($decision['continue_url'], PHP_URL_FRAGMENT), 2)[1];
        $issuerPage = $this->http('GET', self::REST . '/oauth/consent');
        self::assertStringContainsString("connect-src 'self'", $issuerPage->getHeaderLine('Content-Security-Policy'));
        $headers = ['Origin' => self::API_ORIGIN, 'Content-Type' => 'application/x-www-form-urlencoded'];
        $handoff = self::REST . '/oauth/consent';
        self::assertSame(400, $this->http('POST', $handoff, ['ticket' => $ticket], headers: $headers,
            cookies: [DiagnosticOAuth::BROWSER_COOKIE => DiagnosticConfig::secret()])->getStatusCode());
        $cookie = [DiagnosticOAuth::BROWSER_COOKIE => $browser];
        $confirmation = $this->json($this->http('POST', $handoff, ['ticket' => $ticket], headers: $headers, cookies: $cookie));
        parse_str(parse_url($confirmation['callback_url'], PHP_URL_QUERY), $callback);
        self::assertSame('fixture-state', $callback['state']);
        self::assertSame($this->config->issuer(), $callback['iss']);
        self::assertSame(400, $this->http('POST', $handoff, ['ticket' => $ticket], headers: $headers, cookies: $cookie)->getStatusCode());

        $tokenRequest = [
            'grant_type' => 'authorization_code', 'code' => $callback['code'], 'code_verifier' => $verifier,
            'client_id' => self::CLIENT, 'redirect_uri' => self::CALLBACK, 'resource' => $this->config->resource(),
        ];
        $tokenPath = self::REST . '/oauth/token';
        self::assertSame(400, $this->http('POST', $tokenPath, array_replace($tokenRequest, [
            'code_verifier' => DiagnosticConfig::secret(),
        ]), headers: $headers)->getStatusCode());
        $issued = $this->http('POST', $tokenPath, $tokenRequest, headers: $headers);
        self::assertSame(200, $issued->getStatusCode(), (string) $issued->getBody());
        self::assertSame('no-store', $issued->getHeaderLine('Cache-Control'));
        $token = $this->json($issued)['access_token'];
        self::assertSame(400, $this->http('POST', $tokenPath, $tokenRequest, headers: $headers)->getStatusCode());
        self::assertSame(401, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());

        $initialized = $this->json($this->mcp('initialize', [
            'protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'fixture', 'version' => '1'],
        ], version: '2025-11-25'));
        self::assertSame('2025-11-25', $initialized['result']['protocolVersion']);
        $found = $this->json($this->mcp('server/discover', [], version: '2026-07-28'));
        self::assertArrayHasKey('result', $found, json_encode($found, JSON_THROW_ON_ERROR));
        $list = $this->json($this->mcp('tools/list', [], version: '2025-11-25'));
        self::assertCount(3, $list['result']['tools']);
        $stream = $this->http('GET', self::REST . '/mcp');
        self::assertSame(405, $stream->getStatusCode());
        self::assertSame('POST', $stream->getHeaderLine('Allow'));
        self::assertTrue($list['result']['tools'][0]['annotations']['readOnlyHint']);
        self::assertSame(DiagnosticConfig::SCOPE, $list['result']['tools'][0]['securitySchemes'][0]['scopes'][0]);
        $args = ['name' => 'get_log_summary', 'arguments' => (object) []];
        $unauthorized = $this->mcp('tools/call', $args, version: '2025-11-25');
        self::assertSame(401, $unauthorized->getStatusCode(), (string) $unauthorized->getBody());
        self::assertStringContainsString(DiagnosticConfig::RESOURCE_METADATA, $unauthorized->getHeaderLine('WWW-Authenticate'));
        $rest = $this->json($this->http('POST', self::REST . '/summary', [], $grant['issued']['access_token']));
        foreach (['2025-11-25', '2026-07-28'] as $version) {
            $mcp = $this->mcp('tools/call', $args, $token, $version);
            self::assertSame(200, $mcp->getStatusCode(), (string) $mcp->getBody());
            self::assertSame($rest, $this->json($mcp)['result']['structuredContent']);
        }
        $this->http('POST', self::ADMIN . '/sessions/' . $grant['session']['id'] . '/revoke', bearer: 'fixture-admin');
        self::assertSame(401, $this->mcp('tools/call', $args, $token, '2026-07-28')->getStatusCode());

        $system = $this->db->table('system_logs')->where('path', self::REST . '/oauth/token')->get();
        self::assertCount(3, $system);
        foreach ($system as $entry) {
            self::assertNull($entry->request_body);
            self::assertNull($entry->response_body);
            self::assertStringNotContainsString($token, (string) $entry->server_meta);
        }
        $errors = $this->db->table('error_logs')->where('error_type', 'LogDiagnosticFailure')->get();
        self::assertNotEmpty($errors);
        foreach ($errors as $entry) {
            self::assertNull($entry->client_post);
            self::assertNull($entry->client_get);
            self::assertNull($entry->client_cookie);
        }
    }

    public function testMcpRejectsDisallowedOriginWithAnOperationAudit(): void
    {
        $path = self::REST . '/mcp';
        $response = $this->http('POST', $path, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ], headers: ['Origin' => 'https://other.diagnostics.test']);
        self::assertSame(403, $response->getStatusCode());
        $requestId = $response->getHeaderLine('X-Request-ID');
        $audit = $this->db->table('audit_logs')
            ->where('request_id', $requestId)
            ->where('action', 'log_diagnostic_rejected')
            ->sole();
        self::assertSame('log_diagnostics', $audit->operation_category);
        self::assertSame($path, $audit->endpoint);
        self::assertSame(403, (int) $audit->response_code);
        self::assertSame(
            ['channel' => 'mcp', 'reason' => 'invalid_origin'],
            json_decode($audit->data, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertSame('invalid_origin', $this->db->table('error_logs')->where('request_id', $requestId)->value('error_message'));
    }

    public function testRevocationBeforeDeliveryDiscardsAnInFlightResult(): void
    {
        $grant = $this->grant(['system']);
        $access = $this->container->get(DiagnosticAccess::class);
        $agent = (new ServerRequestFactory())->createServerRequest('POST', self::API_ORIGIN . self::REST . '/summary')
            ->withHeader('Authorization', 'Bearer ' . $grant['issued']['access_token']);
        $admin = (new ServerRequestFactory())->createServerRequest('POST', self::API_ORIGIN . self::ADMIN . '/sessions')
            ->withAttribute('user_id', 1);
        try {
            $access->search($agent, 'rest', 'get_log_summary', [], static function () use ($access, $admin, $grant): array {
                $access->revoke($admin, $grant['session']['id']);
                return ['log_count' => 1, 'protected_result' => 'unpublished'];
            });
            self::fail('The result was delivered after revocation.');
        } catch (DiagnosticProblem $e) {
            self::assertSame(401, $e->status);
        }
        self::assertSame(0, $this->db->table('log_diagnostic_query_leases')->count());
        self::assertSame(0, (int) $this->db->table('log_diagnostic_sessions')->value('query_count'));
    }

    public function testLegacyMysqlTimeoutCancelsTheQueryAndReleasesTheLease(): void
    {
        if (!$this->db instanceof MySqlConnection || $this->db->isMaria()
            || version_compare($this->db->getServerVersion(), '5.7.8', '>=')) {
            self::markTestSkipped('Requires an isolated MySQL 5.6 server.');
        }

        $utc = new DateTimeZone('UTC');
        $this->container->set(DiagnosticConfig::class, new DiagnosticConfig(
            true, self::API_ORIGIN, self::FRONTEND_ORIGIN, str_repeat('k', 32), $utc, $utc, timeoutMs: 350
        ));
        $this->db->table('llm_logs')->insert(['created_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->db->statement('RENAME TABLE llm_logs TO fixture_llm_logs');
        $this->db->statement('CREATE VIEW llm_logs AS SELECT * FROM fixture_llm_logs WHERE SLEEP(3) = 0');
        $token = $this->grant(['llm', 'system'])['issued']['access_token'];

        $start = hrtime(true);
        $response = $this->http('POST', self::REST . '/summary', ['types' => ['llm']], $token);
        self::assertSame(504, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('query_timeout', $this->json($response)['error']);
        self::assertLessThan(2000, (hrtime(true) - $start) / 1000000);
        self::assertSame(0, $this->db->table('log_diagnostic_query_leases')->count());
        self::assertSame(0, (int) $this->db->table('log_diagnostic_sessions')->value('query_count'));
        self::assertTrue($this->db->table('audit_logs')->where('action', 'log_diagnostic_query')
            ->where('response_code', 504)->exists());
        self::assertTrue($this->db->table('error_logs')->where('error_type', 'LogDiagnosticFailure')
            ->where('error_message', 'query_timeout')->exists());

        $waitUntil = hrtime(true) + 500000000;
        do {
            $remaining = (int) $this->db->selectOne(
                'SELECT COUNT(*) AS active FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()',
                [$this->testDatabase]
            )->active;
            if ($remaining === 0) {
                break;
            }
            usleep(10000);
        } while (hrtime(true) < $waitUntil);
        self::assertSame(0, $remaining, 'The timed-out MySQL connection is still running.');
        self::assertSame(200, $this->http('POST', self::REST . '/summary', ['types' => ['system']], $token)->getStatusCode());
    }

    public function testAuditFailureBlocksOutputAndReleasesTheLease(): void
    {
        $token = $this->grant(['system'])['issued']['access_token'];
        $this->db->getSchemaBuilder()->drop('audit_logs');
        $response = $this->http('POST', self::REST . '/summary', [], $token);
        self::assertSame(503, $response->getStatusCode());
        self::assertArrayNotHasKey('counts_by_type', $this->json($response));
        self::assertSame(0, $this->db->table('log_diagnostic_query_leases')->count());
    }

    public function testExpiredAuthorizationInactiveOwnerAndExpiredLeaseCleanup(): void
    {
        $grant = $this->grant(['system']);
        $token = $grant['issued']['access_token'];
        $session = $grant['session']['id'];
        $this->db->table('users')->where('id', 1)->update(['status' => 'inactive']);
        self::assertSame(401, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());
        $this->db->table('users')->where('id', 1)->update(['status' => 'active']);
        for ($i = 0; $i < 2; $i++) {
            $this->db->table('log_diagnostic_query_leases')->insert(['request_id' => Uuid::generateV4(), 'session_id' => $session, 'expires_at' => time() + 60]);
        }
        $busy = $this->http('POST', self::REST . '/summary', [], $token);
        self::assertSame(429, $busy->getStatusCode());
        self::assertSame('1', $busy->getHeaderLine('Retry-After'));
        $this->db->table('log_diagnostic_query_leases')->update(['expires_at' => time() - 1]);
        $cleanup = $this->container->get(DiagnosticOAuth::class)->cleanup();
        self::assertSame(2, $cleanup['deleted_query_leases']);
        self::assertSame(200, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());
        $secondToken = $this->json($this->http('POST', self::ADMIN . '/sessions/' . $session . '/credentials', bearer: 'fixture-admin'))['access_token'];
        $this->db->table('log_diagnostic_sessions')->where('id', $session)->update([
            'rate_minute' => intdiv(time(), 60), 'rate_count' => $this->config->requestsPerMinute,
        ]);
        self::assertSame(429, $this->http('POST', self::REST . '/summary', [], $secondToken)->getStatusCode());
        $this->db->table('log_diagnostic_sessions')->where('id', $session)->update(['expires_at' => time() - 1]);
        self::assertSame(401, $this->http('POST', self::REST . '/summary', [], $token)->getStatusCode());
    }

    private function connection(): Connection
    {
        $port = getenv('CTDIAG_TEST_MYSQL_PORT');
        if ($port === false) {
            return new SQLiteConnection(new PDO('sqlite::memory:'), config: ['driver' => 'sqlite']);
        }
        if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
            throw new \RuntimeException('Use an isolated loopback MySQL server port.');
        }
        $dsn = 'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4';
        $this->mysql = new PDO($dsn, 'root', '');
        $this->testDatabase = 'ctdiag_test_' . bin2hex(random_bytes(8));
        $this->mysql->exec('CREATE DATABASE ' . $this->testDatabase . ' CHARACTER SET utf8mb4');
        $pdo = new PDO($dsn . ';dbname=' . $this->testDatabase, 'root', '');
        $pdo->exec("SET time_zone = '+00:00'");
        return new MySqlConnection($pdo, $this->testDatabase, config: [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => (int) $port,
            'database' => $this->testDatabase,
            'username' => 'root',
            'password' => '',
        ]);
    }

    private function installSchema(): void
    {
        $schema = $this->db->getSchemaBuilder();
        $schema->create('users', static function (Blueprint $t): void {
            $t->integer('id')->primary();
            $t->string('uuid')->nullable();
            $t->integer('is_admin');
            $t->string('status');
            $t->dateTime('deleted_at')->nullable();
        });
        $schema->create('audit_logs', static function (Blueprint $t): void {
            $t->increments('id');
            foreach (['user_id', 'affected_id', 'response_code'] as $column) {
                $t->integer($column)->nullable();
            }
            foreach (['user_uuid', 'conversation_id', 'actor_type', 'action', 'ip_address', 'user_agent', 'request_method',
                'endpoint', 'affected_table', 'status', 'session_id', 'referrer', 'operation_category', 'operation_subtype',
                'change_type', 'request_id'] as $column) {
                $t->string($column, $column === 'operation_category' ? 100 : 255)->nullable();
            }
            foreach (['old_data', 'new_data', 'data'] as $column) {
                $t->longText($column)->nullable();
            }
            $t->dateTime('created_at')->useCurrent();
        });
        $schema->create('system_logs', static function (Blueprint $t): void {
            $t->increments('id');
            foreach (['request_id', 'method', 'path', 'user_uuid', 'ip_address', 'user_agent'] as $column) {
                $t->string($column)->nullable();
            }
            foreach (['request_body', 'response_body', 'server_meta'] as $column) {
                $t->longText($column)->nullable();
            }
            $t->integer('status_code')->nullable();
            $t->integer('user_id')->nullable();
            $t->decimal('duration_ms', 10, 2)->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
        $schema->create('error_logs', static function (Blueprint $t): void {
            $t->increments('id');
            foreach (['request_id', 'error_type', 'error_file', 'script_name'] as $column) {
                $t->string($column)->nullable();
            }
            foreach (['error_message', 'client_get', 'client_post', 'client_files', 'client_cookie', 'client_session', 'client_server'] as $column) {
                $t->text($column)->nullable();
            }
            $t->integer('error_line')->nullable();
            $t->dateTime('error_time')->nullable();
        });
        $schema->create('llm_logs', static function (Blueprint $t): void {
            $t->increments('id');
            foreach (['request_id', 'source', 'model', 'status'] as $column) {
                $t->string($column)->nullable();
            }
            foreach (['prompt', 'response_raw', 'error_message'] as $column) {
                $t->text($column)->nullable();
            }
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $column) {
                $t->integer($column)->nullable();
            }
            $t->decimal('latency_ms', 10, 2)->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
        $migration = file_get_contents(__DIR__ . '/../../database/migrations/20261119_add_log_diagnostics.sql');
        if ($this->mysql !== null) {
            $schema->create('cron_tasks', static function (Blueprint $t): void {
                $t->string('task_key', 64)->primary();
                $t->string('task_name');
                $t->string('description');
                $t->integer('interval_minutes');
                $t->boolean('enabled');
                $t->dateTime('next_run_at');
                $t->string('last_status');
                $t->integer('consecutive_failures');
                $t->text('settings_json');
            });
            foreach (array_filter(array_map('trim', explode(';', $migration))) as $statement) {
                $this->db->unprepared($statement);
            }
            return;
        }
        preg_match_all('/CREATE TABLE log_diagnostic_\w+ \(.+?\n\) ENGINE=InnoDB/s', $migration, $matches);
        foreach ($matches[0] as $create) {
            if (str_contains($create, 'id INT NOT NULL AUTO_INCREMENT')) {
                $create = preg_replace('/^\s+PRIMARY KEY \(id\),\R/m', '', $create);
                $create = str_replace('id INT NOT NULL AUTO_INCREMENT', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $create);
            }
            $create = preg_replace('/^\s+KEY idx_[^\r\n]+\R/m', '', $create);
            $create = preg_replace('/UNIQUE KEY \w+ /', 'UNIQUE ', $create);
            $create = str_replace([' CHARACTER SET ascii COLLATE ascii_bin', ' ENGINE=InnoDB'], '', $create);
            $create = preg_replace('/,\s*\)$/D', "\n)", $create);
            $this->db->unprepared($create);
        }
    }

    private function grant(array $types): array
    {
        $response = $this->http('POST', self::ADMIN . '/sessions', ['types' => $types, 'issue_rest_token' => true], 'fixture-admin');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response);
    }

    private function mcp(string $method, array $params, ?string $bearer = null, string $version = '2025-11-25'): ResponseInterface
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => $version];
        if ($version === '2026-07-28') {
            $params['_meta'] = [
                'io.modelcontextprotocol/protocolVersion' => $version,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ];
            $headers['Mcp-Method'] = $method;
            if ($method === 'tools/call') {
                $headers['Mcp-Name'] = $params['name'];
            }
        }
        return $this->http('POST', self::REST . '/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params,
        ], $bearer, headers: $headers);
    }

    private function http(
        string $method,
        string $path,
        ?array $body = null,
        ?string $bearer = null,
        array $query = [],
        array $headers = [],
        array $cookies = []
    ): ResponseInterface {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $request = (new ServerRequestFactory())->createServerRequest($method, self::API_ORIGIN . $uri)
            ->withQueryParams($query)->withCookieParams($cookies);
        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);
        }
        if ($body !== null) {
            $form = ($headers['Content-Type'] ?? '') === 'application/x-www-form-urlencoded';
            $encoded = $form ? http_build_query($body) : json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR);
            $request = $request->withHeader('Content-Type', $form ? 'application/x-www-form-urlencoded' : 'application/json')
                ->withBody((new StreamFactory())->createStream($encoded));
        }
        foreach ($headers as $key => $value) {
            $request = $request->withHeader($key, $value);
        }
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = $uri;
        try {
            return $this->app->handle($request);
        } finally {
            if ($previousUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
        }
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
