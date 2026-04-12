<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminCronController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminCronControllerTest extends TestCase
{
    private function makeController(
        ?CronSchedulerService $scheduler = null,
        ?AuthService $authService = null,
        ?AuditLogService $audit = null
    ): AdminCronController {
        return new AdminCronController(
            $scheduler ?? $this->createMock(CronSchedulerService::class),
            $authService ?? $this->createMock(AuthService::class),
            $audit ?? $this->createMock(AuditLogService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class)
        );
    }

    public function testListTasksReturnsPayload(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('listTasks')
            ->willReturn([['task_key' => 'support_sla_sweep']]);

        $controller = $this->makeController($scheduler, $auth, $audit);
        $response = $controller->listTasks(
            makeRequest('GET', '/api/v1/admin/cron/tasks'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateTaskReturnsValidationError(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('updateTask')
            ->with('support_sla_sweep', ['interval_minutes' => 0])
            ->willThrowException(new \InvalidArgumentException('interval_minutes must be between 1 and 1440'));

        $controller = $this->makeController($scheduler, $auth, $this->createMock(AuditLogService::class));
        $response = $controller->updateTask(
            makeRequest('PUT', '/api/v1/admin/cron/tasks/support_sla_sweep', ['interval_minutes' => 0]),
            new \Slim\Psr7\Response(),
            ['taskKey' => 'support_sla_sweep']
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('request_id', $payload);
    }

    public function testUpdateTaskReturnsNotFoundForUnknownTask(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('updateTask')
            ->with('missing_task', [])
            ->willThrowException(new \RuntimeException('Cron task not found'));

        $controller = $this->makeController($scheduler, $auth, $this->createMock(AuditLogService::class));
        $response = $controller->updateTask(
            makeRequest('PUT', '/api/v1/admin/cron/tasks/missing_task', []),
            new \Slim\Psr7\Response(),
            ['taskKey' => 'missing_task']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListRunsReturnsPayload(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('listRuns')
            ->with([])
            ->willReturn(['items' => [['id' => 1]], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 1]]);

        $controller = $this->makeController($scheduler, $auth, $audit);
        $response = $controller->listRuns(
            makeRequest('GET', '/api/v1/admin/cron/runs'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRunTaskReturnsPayload(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runTaskNow')
            ->with('support_sla_sweep', 'admin_manual', $this->arrayHasKey('request_id'))
            ->willReturn(['task_key' => 'support_sla_sweep', 'status' => 'success']);

        $controller = $this->makeController($scheduler, $auth, $audit);
        $response = $controller->runTask(
            makeRequest('POST', '/api/v1/admin/cron/tasks/support_sla_sweep/run'),
            new \Slim\Psr7\Response(),
            ['taskKey' => 'support_sla_sweep']
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRunTaskReturnsFailureWhenTaskRunFails(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runTaskNow')
            ->willReturn([
                'task_key' => 'support_sla_sweep',
                'status' => 'failed',
                'error_message' => 'task_failed',
            ]);

        $controller = $this->makeController($scheduler, $auth, $audit);
        $response = $controller->runTask(
            makeRequest('POST', '/api/v1/admin/cron/tasks/support_sla_sweep/run'),
            new \Slim\Psr7\Response(),
            ['taskKey' => 'support_sla_sweep']
        );

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
    }

    public function testRunTaskReturnsServerErrorForUnexpectedRuntimeException(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runTaskNow')
            ->willThrowException(new \RuntimeException('database offline'));

        $controller = $this->makeController($scheduler, $auth, $this->createMock(AuditLogService::class));
        $response = $controller->runTask(
            makeRequest('POST', '/api/v1/admin/cron/tasks/support_sla_sweep/run'),
            new \Slim\Psr7\Response(),
            ['taskKey' => 'support_sla_sweep']
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testJsonFallsBackWhenPayloadCannotBeEncoded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $errorLogService = $this->createMock(ErrorLogService::class);
        $errorLogService->expects($this->once())->method('logException');

        $controller = new AdminCronController(
            $this->createMock(CronSchedulerService::class),
            $this->createMock(AuthService::class),
            $this->createMock(AuditLogService::class),
            $logger,
            $errorLogService
        );

        $method = new \ReflectionMethod($controller, 'json');
        $method->setAccessible(true);

        $request = makeRequest('GET', '/api/v1/admin/cron/tasks')->withAttribute('request_id', 'req-admin-cron-json');
        $response = new \Slim\Psr7\Response();
        $invalidPayload = ['message' => "\xB1\x31"];

        $result = $method->invoke($controller, $request, $response, $invalidPayload, 500);
        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['success']);
        $this->assertSame('JSON_ENCODE_ERROR', $payload['code']);
        $this->assertSame('req-admin-cron-json', $payload['request_id']);
    }
}
