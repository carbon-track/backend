<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\CronController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CronControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['CRON_RUN_KEY']);
    }

    public function testRunReturnsServiceUnavailableWhenCronKeyIsMissing(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new CronController(
            $this->createMock(CronSchedulerService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('CRON_UNAVAILABLE', $payload['code']);
        $this->assertArrayHasKey('request_id', $payload);
    }

    public function testRunReturnsForbiddenForInvalidKey(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new CronController(
            $this->createMock(CronSchedulerService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'bad']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('request_id', $payload);
    }

    public function testRunStillReturnsForbiddenWhenAuditLogFails(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('logSystemEvent')
            ->willThrowException(new \RuntimeException('audit down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $controller = new CronController(
            $this->createMock(CronSchedulerService::class),
            $logger,
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'bad']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRunReturnsSchedulerSummaryForValidKey(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runDueTasks')
            ->with('cron_endpoint', $this->arrayHasKey('request_id'))
            ->willReturn([
                'triggered_at' => '2026-04-10 12:00:00',
                'due' => ['support_sla_sweep'],
                'executed' => [['task_key' => 'support_sla_sweep', 'status' => 'success']],
                'failed' => [],
                'skipped' => [],
            ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new CronController(
            $scheduler,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'expected-secret']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRunReturnsFailureWhenDueTaskFails(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runDueTasks')
            ->willReturn([
                'triggered_at' => '2026-04-10 12:00:00',
                'due' => ['support_sla_sweep'],
                'executed' => [],
                'failed' => [['task_key' => 'support_sla_sweep', 'status' => 'failed']],
                'skipped' => [],
            ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new CronController(
            $scheduler,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'expected-secret']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
    }

    public function testRunReturnsConflictWhenAllDueTasksAreSkipped(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runDueTasks')
            ->willReturn([
                'triggered_at' => '2026-04-10 12:00:00',
                'due' => ['support_sla_sweep'],
                'executed' => [],
                'failed' => [],
                'skipped' => [['task_key' => 'support_sla_sweep', 'status' => 'skipped']],
            ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new CronController(
            $scheduler,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'expected-secret']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
    }

    public function testRunReturnsConflictWhenBatchIsPartiallySkipped(): void
    {
        $_ENV['CRON_RUN_KEY'] = 'expected-secret';

        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runDueTasks')
            ->willReturn([
                'triggered_at' => '2026-04-10 12:00:00',
                'due' => ['support_sla_sweep', 'leaderboard_refresh'],
                'executed' => [['task_key' => 'leaderboard_refresh', 'status' => 'success']],
                'failed' => [],
                'skipped' => [['task_key' => 'support_sla_sweep', 'status' => 'skipped']],
            ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('logSystemEvent')
            ->with(
                'cron_run_endpoint_triggered',
                'cron_scheduler',
                $this->callback(static function (array $context): bool {
                    return ($context['status'] ?? null) === 'failed';
                })
            )
            ->willReturn(true);

        $controller = new CronController(
            $scheduler,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $audit
        );

        $response = $controller->run(
            makeRequest('GET', '/api/v1/cron/run', null, ['key' => 'expected-secret']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
    }
}
