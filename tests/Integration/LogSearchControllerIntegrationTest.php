<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Controllers\LogSearchController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use PHPUnit\Framework\TestCase;
use PDO;
use Slim\Psr7\Response;

class LogSearchControllerIntegrationTest extends TestCase
{
    private function makeController(PDO $pdo): LogSearchController
    {
        $authService = new class('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 'HS256', 3600) extends AuthService {
            private array $admin = [
                'id' => 1,
                'is_admin' => true,
            ];

            public function getCurrentUser(\Psr\Http\Message\ServerRequestInterface $request): ?array
            {
                return $this->admin;
            }
        };

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->method('log')->willReturn(true);
        $auditLogService->method('logAdminOperation')->willReturn(true);

        return new LogSearchController($pdo, $authService, $auditLogService);
    }

    public function testSearchFiltersAuditLlmAndErrorByConversationIdAndTurnNo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("
            INSERT INTO audit_logs (user_id, conversation_id, actor_type, action, status, operation_category, request_id, data, created_at)
            VALUES
            (1, 'admin-ai-11111111', 'admin', 'admin_ai_user_message', 'success', 'admin_ai', 'req-conv-1', '{\"visible_text\":\"会话一\"}', '2026-03-20 10:00:00'),
            (1, 'admin-ai-22222222', 'admin', 'admin_ai_user_message', 'success', 'admin_ai', 'req-conv-2', '{\"visible_text\":\"会话二\"}', '2026-03-21 10:00:00')
        ");
        $pdo->exec("
            INSERT INTO llm_logs (request_id, actor_type, actor_id, conversation_id, turn_no, source, model, prompt, response_raw, status, total_tokens, created_at)
            VALUES
            ('req-conv-1', 'admin', 1, 'admin-ai-11111111', 2, '/admin/ai/chat', 'gpt-5.4', 'ping', '{\"ok\":true}', 'success', 15, '2026-03-20 10:01:00'),
            ('req-conv-2', 'admin', 1, 'admin-ai-22222222', 5, '/admin/ai/chat', 'gemini-2.5-flash', 'pong', '{\"ok\":true}', 'success', 12, '2026-03-21 10:01:00')
        ");
        $pdo->exec("
            INSERT INTO error_logs (request_id, error_type, error_message, error_time)
            VALUES
            ('req-conv-1', 'RuntimeException', 'boom-1', '2026-03-20 10:02:00'),
            ('req-conv-2', 'RuntimeException', 'boom-2', '2026-03-21 10:02:00')
        ");

        $controller = $this->makeController($pdo);
        $request = makeRequest('GET', '/admin/logs/search', null, [
            'types' => 'audit,error,llm',
            'conversation_id' => 'admin-ai-11111111',
            'turn_no' => '2',
        ]);
        $response = $controller->search($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);

        $this->assertCount(1, $payload['data']['audit']['items']);
        $this->assertSame('admin-ai-11111111', $payload['data']['audit']['items'][0]['conversation_id']);
        $this->assertSame('req-conv-1', $payload['data']['audit']['items'][0]['request_id']);

        $this->assertCount(1, $payload['data']['llm']['items']);
        $this->assertSame('admin-ai-11111111', $payload['data']['llm']['items'][0]['conversation_id']);
        $this->assertSame(2, $payload['data']['llm']['items'][0]['turn_no']);

        $this->assertCount(1, $payload['data']['error']['items']);
        $this->assertSame('req-conv-1', $payload['data']['error']['items'][0]['request_id']);
    }

    public function testSearchFiltersAuditByRequestIdAndReturnsRequestIdColumn(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("
            INSERT INTO audit_logs (user_id, conversation_id, actor_type, action, status, operation_category, request_id, data, created_at)
            VALUES
            (1, 'admin-ai-33333333', 'admin', 'admin_ai_user_message', 'success', 'admin_ai', 'req-audit-1', '{\"visible_text\":\"命中\"}', '2026-03-22 10:00:00'),
            (1, 'admin-ai-44444444', 'admin', 'admin_ai_user_message', 'success', 'admin_ai', 'req-audit-2', '{\"visible_text\":\"忽略\"}', '2026-03-22 10:05:00')
        ");

        $controller = $this->makeController($pdo);
        $request = makeRequest('GET', '/admin/logs/search', null, [
            'types' => 'audit',
            'request_id' => 'req-audit-1',
        ]);
        $response = $controller->search($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']['audit']['items']);
        $this->assertSame('req-audit-1', $payload['data']['audit']['items'][0]['request_id']);
    }
}
