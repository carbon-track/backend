<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminAiController;
use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Services\AdminAiCommandRepository;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;

class AdminAiControllerTest extends TestCase
{
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

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test-fingerprint');
        $commandRepo->method('getActivePath')->willReturn('/path/config.php');
        $commandRepo->method('getLastModified')->willReturn(1234567890);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $commandRepo,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/intents', ['query' => '打开用户管理']);
        $response = $controller->analyze($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('navigate', $payload['intent']['type']);
        $this->assertSame('users', $payload['intent']['target']['routeId']);
        $this->assertSame('test', $payload['metadata']['model']);
        $this->assertArrayHasKey('timestamp', $payload['metadata']);
    }

    public function testAnalyzeReturns503WhenServiceDisabled(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin']);
        $authService->method('isAdminUser')->willReturn(true);

        $intentService = $this->createMock(AdminAiIntentService::class);
        $intentService->method('isEnabled')->willReturn(false);

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(null);
        $commandRepo->method('getLastModified')->willReturn(null);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $commandRepo,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/intents', ['query' => 'something']);
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

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn(null);
        $commandRepo->method('getLastModified')->willReturn(null);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $commandRepo,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('POST', '/admin/ai/intents', ['query' => '  ']);
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

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn('/path/config.php');
        $commandRepo->method('getLastModified')->willReturn(987654321);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $commandRepo,
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

        $commandRepo = $this->createMock(AdminAiCommandRepository::class);
        $commandRepo->method('getFingerprint')->willReturn('test');
        $commandRepo->method('getActivePath')->willReturn('/path/config.php');
        $commandRepo->method('getLastModified')->willReturn(987654321);

        $controller = new AdminAiController(
            $authService,
            $intentService,
            $commandRepo,
            $this->createMock(ErrorLogService::class),
            new NullLogger()
        );

        $request = makeRequest('GET', '/admin/ai/diagnostics', null, ['check' => 'true']);
        $response = $controller->diagnostics($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $payload['diagnostics']['connectivity']['status']);
    }
}

