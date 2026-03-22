<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminAiController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AdminAnnouncementAiUnavailableException;
use CarbonTrack\Services\AdminAnnouncementAiService;
use CarbonTrack\Services\AdminAiAgentService;
use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Services\AdminAiCommandRepository;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;

class AdminAiControllerTest extends TestCase
{
    private const ACTIVE_CONFIG_PATH = '/path/config.php';
    private const INTENT_ROUTE = '/admin/ai/intents';
    private const CHAT_ROUTE = '/admin/ai/chat';

    public function testAnalyzeReturnsParsedIntent(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService->method('isEnabled')->willReturn(true);
        $intentService->method('analyzeIntent')->willReturn([
            'intent' => [
                'type' => 'navigate',
                'label' => 'User Management',
                'confidence' => 0.91,
                'target' => [
                    'routeId' => 'users',
                    'route' => '/admin/users',
                    'mode' => 'navigation',
                    'query' => [],
                ],
                'missing' => [],
            ],
            'alternatives' => [],
            'metadata' => [
                'model' => 'test',
                'usage' => null,
                'finish_reason' => 'stop',
            ],
        ]);

        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test-fingerprint');
        $commandRepo->method('getActivePath')->willReturn(self::ACTIVE_CONFIG_PATH);
        $commandRepo->method('getLastModified')->willReturn(1234567890);
        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $auditLogService,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', self::INTENT_ROUTE, ['query' => '打开用户管理']);
        $response = $controller->analyze($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('navigate', $payload['intent']['type']);
        $this->assertSame('users', $payload['intent']['target']['routeId']);
        $this->assertSame('test', $payload['metadata']['model']);
        $this->assertArrayHasKey('timestamp', $payload['metadata']);
    }

    public function testChatReturnsConversationPayload(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);
        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);

        $agentService = $this->createMock(AdminAiAgentService::class);
        $agentService->method('isEnabled')->willReturn(true);
        $agentService->expects($this->once())
            ->method('chat')
            ->with(
                null,
                '帮我汇总最近的 AI 会话',
                $this->isType('array'),
                null,
                $this->isType('array')
            )
            ->willReturn([
                'success' => true,
                'conversation_id' => 'admin-ai-12345678',
                'message' => '已整理最近的 AI 会话情况。',
                'conversation' => [
                    'conversation_id' => 'admin-ai-12345678',
                    'summary' => ['message_count' => 2],
                    'messages' => [],
                    'llm_calls' => [],
                    'pending_actions' => [],
                ],
            ]);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $auditLogService,
            $this->createMock(ErrorLogService::class),
            new NullLogger(),
            $agentService
        );

        $request = makeRequest('POST', self::CHAT_ROUTE, [
            'message' => '帮我汇总最近的 AI 会话',
            'context' => ['activeRoute' => '/admin/llm-usage'],
        ]);
        $response = $controller->chat($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('admin-ai-12345678', $payload['conversation_id']);
    }

    public function testConversationsReturnsSessionList(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $agentService = $this->createMock(AdminAiAgentService::class);
        $agentService->expects($this->once())
            ->method('listConversations')
            ->with([
                'limit' => '10',
                'actor_id' => null,
                'admin_id' => '7',
                'status' => 'waiting_confirmation',
                'model' => 'gpt-5.4',
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-22',
                'has_pending_action' => 'true',
                'conversation_id' => 'admin-ai-1',
            ])
            ->willReturn([
                [
                    'conversation_id' => 'admin-ai-1',
                    'title' => '测试会话',
                    'message_count' => 3,
                ],
            ]);

        $controller = new AdminAiController(
            $authService,
            $this->createMock(AdminAiIntentService::class),
            $this->createMock(AdminAnnouncementAiService::class),
            $this->createMock(AdminAiCommandRepository::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger(),
            $agentService
        );

        $request = makeRequest('GET', '/admin/ai/conversations', null, [
            'limit' => '10',
            'admin_id' => '7',
            'status' => 'waiting_confirmation',
            'model' => 'gpt-5.4',
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-22',
            'has_pending_action' => 'true',
            'conversation_id' => 'admin-ai-1',
        ]);
        $response = $controller->conversations($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('admin-ai-1', $payload['data'][0]['conversation_id']);
    }

    public function testConversationDetailReturnsTimeline(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $agentService = $this->createMock(AdminAiAgentService::class);
        $agentService->expects($this->once())
            ->method('getConversationDetail')
            ->with('admin-ai-2')
            ->willReturn([
                'conversation_id' => 'admin-ai-2',
                'summary' => ['message_count' => 4],
                'messages' => [['id' => 1, 'kind' => 'message', 'role' => 'user']],
                'llm_calls' => [],
                'pending_actions' => [],
            ]);

        $controller = new AdminAiController(
            $authService,
            $this->createMock(AdminAiIntentService::class),
            $this->createMock(AdminAnnouncementAiService::class),
            $this->createMock(AdminAiCommandRepository::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger(),
            $agentService
        );

        $request = makeRequest('GET', '/admin/ai/conversations/admin-ai-2');
        $response = $controller->conversationDetail($request, new Response(), ['conversation_id' => 'admin-ai-2']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(4, $payload['data']['summary']['message_count']);
    }

    public function testAnalyzeReturns503WhenServiceDisabled(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService->method('isEnabled')->willReturn(false);

        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);
        $announcementAiService->method('isEnabled')->willReturn(false);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(null);
        $commandRepo->method('getLastModified')->willReturn(null);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', self::INTENT_ROUTE, ['query' => 'something']);
        $response = $controller->analyze($request, new Response());

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('AI_DISABLED', $payload['code']);
    }

    public function testAnalyzeValidatesMissingQuery(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService->method('isEnabled')->willReturn(true);

        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(null);
        $commandRepo->method('getLastModified')->willReturn(null);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', self::INTENT_ROUTE, ['query' => '  ']);
        $response = $controller->analyze($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('INVALID_QUERY', $payload['code']);
    }

    public function testDiagnosticsReturnsData(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService
            ->expects($this->once())
            ->method('getDiagnostics')
            ->with(false)
            ->willReturn([
                'enabled' => true,
                'connectivity' => ['status' => 'not_checked'],
            ]);

        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(self::ACTIVE_CONFIG_PATH);
        $commandRepo->method('getLastModified')->willReturn(987654321);
        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $auditLogService,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('GET', '/admin/ai/diagnostics');
        $response = $controller->diagnostics($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['diagnostics']['enabled']);
        $this->assertSame('not_checked', $payload['diagnostics']['connectivity']['status']);
    }

    public function testDiagnosticsHonorsConnectivityFlag(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService
            ->expects($this->once())
            ->method('getDiagnostics')
            ->with(true)
            ->willReturn([
                'enabled' => true,
                'connectivity' => ['status' => 'ok'],
            ]);

        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(self::ACTIVE_CONFIG_PATH);
        $commandRepo->method('getLastModified')->willReturn(987654321);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('GET', '/admin/ai/diagnostics', null, ['check' => 'true']);
        $response = $controller->diagnostics($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $payload['diagnostics']['connectivity']['status']);
    }

    public function testGenerateAnnouncementDraftReturnsGeneratedPayload(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);
        $announcementAiService->method('isEnabled')->willReturn(true);
        $announcementAiService->expects($this->once())
            ->method('generateDraft')
            ->with($this->callback(function (array $payload) {
                return $payload['action'] === 'generate'
                    && $payload['priority'] === 'high'
                    && $payload['content_format'] === 'html';
            }), $this->anything())
            ->willReturn([
                'success' => true,
                'result' => [
                    'title' => 'Generated announcement',
                    'content' => '<p>Hello admin</p>',
                    'content_format' => 'html',
                    'action' => 'generate',
                ],
                'metadata' => [
                    'model' => 'test-model',
                    'usage' => ['total_tokens' => 10],
                ],
            ]);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $auditLogService,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/announcement-drafts', [
            'action' => 'generate',
            'title' => 'Maintenance',
            'content' => 'Need a draft',
            'priority' => 'high',
            'content_format' => 'html',
            'instruction' => 'Keep it concise',
        ]);
        $response = $controller->generateAnnouncementDraft($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Generated announcement', $payload['data']['title']);
        $this->assertSame('test-model', $payload['metadata']['model']);
        $this->assertArrayHasKey('timestamp', $payload['metadata']);
    }

    public function testGenerateAnnouncementDraftValidatesAction(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);
        $announcementAiService->method('isEnabled')->willReturn(true);
        $commandRepo = $this->createMock(AdminAiCommandRepository::class);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/announcement-drafts', [
            'action' => 'explode',
            'title' => 'Maintenance',
        ]);
        $response = $controller->generateAnnouncementDraft($request, new Response());

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('INVALID_ACTION', $payload['code']);
    }

    public function testGenerateAnnouncementDraftReturns503WhenProviderUnavailable(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $announcementAiService = $this->createMock(AdminAnnouncementAiService::class);
        $announcementAiService->method('isEnabled')->willReturn(true);
        $announcementAiService->method('generateDraft')
            ->willThrowException(new AdminAnnouncementAiUnavailableException('LLM_UNAVAILABLE'));

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $announcementAiService,
            $commandRepo,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/announcement-drafts', [
            'action' => 'generate',
            'title' => 'Maintenance',
            'content' => 'Need a draft',
            'priority' => 'high',
            'content_format' => 'html',
        ]);
        $response = $controller->generateAnnouncementDraft($request, new Response());

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('AI_UNAVAILABLE', $payload['code']);
    }
}

