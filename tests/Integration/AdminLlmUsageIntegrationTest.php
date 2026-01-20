<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Controllers\AdminLlmUsageController;
use CarbonTrack\Services\AuthService;
use PHPUnit\Framework\TestCase;
use PDO;
use Slim\Psr7\Response;

class AdminLlmUsageIntegrationTest extends TestCase
{
    private function makeController(PDO $pdo): AdminLlmUsageController
    {
        $authService = new class('test-secret', 'HS256', 3600) extends AuthService {
            private array $admin = [
                'id' => 1,
                'is_admin' => true,
            ];

            public function getCurrentUser(\Psr\Http\Message\ServerRequestInterface $request): ?array
            {
                return $this->admin;
            }
        };

        return new AdminLlmUsageController($pdo, $authService);
    }

    public function testSummaryReturnsUsageAndUsers(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("INSERT INTO user_groups (id, name, code, config, is_default) VALUES (1, 'Free', 'free', '{\"llm\":{\"daily_limit\":10,\"rate_limit\":60}}', 1)");
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, group_id) VALUES (2, 'user_a', 'usera@example.com', 'active', 0, 1)");
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, group_id) VALUES (3, 'admin_b', 'adminb@example.com', 'active', 1, 1)");

        $lastUpdated = date('Y-m-d H:i:s', strtotime('-1 day'));
        $resetAt = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $pdo->exec("INSERT INTO user_usage_stats (user_id, resource_key, counter, last_updated_at, reset_at) VALUES (2, 'llm_daily', 4, '{$lastUpdated}', '{$resetAt}')");

        $logTime = date('Y-m-d H:i:s', strtotime('-2 days'));
        $pdo->exec("INSERT INTO llm_logs (request_id, actor_type, actor_id, source, model, prompt, response_raw, status, total_tokens, created_at)
            VALUES ('req-1', 'user', 2, 'smart-activity-input', 'test-model', 'hello', '{\"ok\":true}', 'success', 12, '{$logTime}')");

        $controller = $this->makeController($pdo);
        $request = makeRequest('GET', '/admin/llm-usage');
        $response = $controller->summary($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(10, $payload['data']['users'][0]['daily_limit']);
        $this->assertSame(4, $payload['data']['users'][0]['daily_used']);
        $this->assertSame(1, $payload['data']['summary']['calls_30d']);
    }

    public function testLogDetailReturnsRecord(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $logTime = date('Y-m-d H:i:s', strtotime('-1 hour'));
        $pdo->exec("INSERT INTO llm_logs (id, request_id, actor_type, actor_id, source, model, prompt, response_raw, status, total_tokens, created_at)
            VALUES (10, 'req-10', 'admin', 1, 'admin-command', 'model-x', 'ping', '{\"answer\":\"ok\"}', 'success', 5, '{$logTime}')");

        $controller = $this->makeController($pdo);
        $request = makeRequest('GET', '/admin/llm-usage/logs/10');
        $response = $controller->logDetail($request, new Response(), ['id' => 10]);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('req-10', $payload['data']['request_id']);
        $this->assertSame('admin', $payload['data']['actor_type']);
    }

    public function testAnalyticsReturnsTrendsAndRecentConversations(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin) VALUES (2, 'user_a', 'usera@example.com', 'active', 0)");

        $logTime = date('Y-m-d H:i:s', strtotime('-1 day'));
        $pdo->exec("INSERT INTO llm_logs (id, request_id, actor_type, actor_id, source, model, prompt, response_raw, status, total_tokens, latency_ms, created_at, context_json)
            VALUES (20, 'req-20', 'user', 2, 'smart-activity-input', 'model-x', 'hello', '{\"ok\":true}', 'success', 12, 900, '{$logTime}', '{\"client_timezone\":\"UTC\"}')");
        $pdo->exec("INSERT INTO system_logs (request_id, method, path, status_code, created_at)
            VALUES ('req-20', 'POST', '/api/v1/ai/suggest-activity', 200, '{$logTime}')");

        $controller = $this->makeController($pdo);
        $request = makeRequest('GET', '/admin/llm-usage/analytics', null, ['days' => 7, 'recent_limit' => 5]);
        $response = $controller->analytics($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['data']['trends']);
        $this->assertNotEmpty($payload['data']['recent_conversations']);
        $this->assertSame('req-20', $payload['data']['recent_conversations'][0]['request_id']);
        $this->assertSame(1, $payload['data']['recent_conversations'][0]['related']['system']);
    }
}
