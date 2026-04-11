<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AdminAiReadModelService;
use CarbonTrack\Services\AdminAiWriteActionService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\MessageService;
use PHPUnit\Framework\TestCase;

class AdminAiCronActionsTest extends TestCase
{
    public function testReadModelSupportsCronTasksAndRuns(): void
    {
        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('listTasks')
            ->willReturn([['task_key' => 'support_sla_sweep']]);
        $scheduler->expects($this->once())
            ->method('listRuns')
            ->with(['task_key' => 'support_sla_sweep'])
            ->willReturn([
                'items' => [['id' => 1, 'task_key' => 'support_sla_sweep']],
                'pagination' => ['page' => 1, 'limit' => 20, 'total' => 1],
            ]);

        $service = new AdminAiReadModelService(
            new \PDO('sqlite::memory:'),
            null,
            $scheduler
        );

        $tasks = $service->execute('get_cron_tasks', []);
        $runs = $service->execute('get_cron_runs', ['task_key' => 'support_sla_sweep']);

        $this->assertSame('cron_tasks', $tasks['scope']);
        $this->assertSame('cron_runs', $runs['scope']);
        $this->assertCount(1, $tasks['items']);
        $this->assertCount(1, $runs['items']);
    }

    public function testWriteModelSupportsCronTaskUpdateAndRun(): void
    {
        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('updateTask')
            ->with('support_sla_sweep', ['enabled' => false, 'interval_minutes' => 15])
            ->willReturn(['task_key' => 'support_sla_sweep', 'enabled' => false, 'interval_minutes' => 15]);
        $scheduler->expects($this->once())
            ->method('runTaskNow')
            ->with('support_sla_sweep', 'admin_manual', $this->arrayHasKey('request_id'))
            ->willReturn(['task_key' => 'support_sla_sweep', 'status' => 'success']);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->exactly(2))->method('logAdminOperation');

        $service = new AdminAiWriteActionService(
            new \PDO('sqlite::memory:'),
            $audit,
            $this->createMock(MessageService::class),
            $this->createMock(BadgeService::class),
            $scheduler
        );

        $updateResult = $service->execute('update_cron_task', [
            'task_key' => 'support_sla_sweep',
            'enabled' => false,
            'interval_minutes' => 15,
        ], [
            'actor_id' => 1,
            'request_id' => 'req-1',
            'conversation_id' => 'conv-1',
        ]);

        $runResult = $service->execute('run_cron_task', [
            'task_key' => 'support_sla_sweep',
        ], [
            'actor_id' => 1,
            'request_id' => 'req-2',
            'conversation_id' => 'conv-2',
        ]);

        $this->assertSame('update_cron_task', $updateResult['action']);
        $this->assertSame('run_cron_task', $runResult['action']);
        $this->assertSame('success', $runResult['task_run']['status']);
    }

    public function testWriteModelThrowsWhenCronRunIsNotSuccessful(): void
    {
        $scheduler = $this->createMock(CronSchedulerService::class);
        $scheduler->expects($this->once())
            ->method('runTaskNow')
            ->willReturn([
                'task_key' => 'support_sla_sweep',
                'status' => 'failed',
                'error_message' => 'task_failed',
            ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $service = new AdminAiWriteActionService(
            new \PDO('sqlite::memory:'),
            $audit,
            $this->createMock(MessageService::class),
            $this->createMock(BadgeService::class),
            $scheduler
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('task_failed');

        $service->execute('run_cron_task', [
            'task_key' => 'support_sla_sweep',
        ], [
            'actor_id' => 1,
            'request_id' => 'req-3',
            'conversation_id' => 'conv-3',
        ]);
    }
}
