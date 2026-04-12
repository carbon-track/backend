<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Models\CronRun;
use CarbonTrack\Models\CronTask;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LeaderboardService;
use CarbonTrack\Services\StreakLeaderboardService;
use CarbonTrack\Services\SupportRoutingEngineService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CronSchedulerServiceTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::$capsule->schema()->create('cron_tasks', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('task_key');
            $table->string('task_name');
            $table->string('description')->nullable();
            $table->integer('interval_minutes')->default(5);
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status')->default('idle');
            $table->text('last_error')->nullable();
            $table->integer('last_duration_ms')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->string('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('settings_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('cron_runs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('task_key');
            $table->string('trigger_source');
            $table->string('request_id')->nullable();
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        CronRun::query()->delete();
        CronTask::query()->delete();
    }

    public function testRunDueTasksExecutesAllRegisteredTasks(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $now);
        $this->seedTask(CronSchedulerService::TASK_BADGE_AUTO_AWARD, 'Badge Auto Award', 5, true, $now);
        $this->seedTask(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'Leaderboard Refresh', 10, true, $now);
        $this->seedTask(CronSchedulerService::TASK_STREAK_LEADERBOARD_REFRESH, 'Streak Leaderboard Refresh', 10, true, $now);

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturn(['processed' => 2, 'breached' => 1, 'rerouted' => 1]);

        $badge = $this->createMock(BadgeService::class);
        $badge->expects($this->once())->method('runAutoGrant')->willReturn(['awarded' => 3, 'skipped' => 4, 'badges' => 2, 'users' => 5]);

        $leaderboard = $this->createMock(LeaderboardService::class);
        $leaderboard->expects($this->once())->method('rebuildCache')->with('cron')->willReturn([
            'generated_at' => '2026-04-10T00:00:00Z',
            'expires_at' => '2026-04-10T00:10:00Z',
            'global' => [1, 2],
            'regions' => [1],
            'schools' => [1, 2, 3],
        ]);

        $streak = $this->createMock(StreakLeaderboardService::class);
        $streak->expects($this->once())->method('rebuildCache')->with('cron')->willReturn([
            'generated_at' => '2026-04-10T00:00:00Z',
            'expires_at' => '2026-04-10T00:10:00Z',
            'global' => [1],
            'regions' => [1, 2],
            'schools' => [1],
        ]);

        $service = $this->makeService($support, $badge, $leaderboard, $streak);
        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-1']);

        $this->assertCount(4, $result['due']);
        $this->assertCount(4, $result['executed']);
        $this->assertCount(0, $result['failed']);
        $this->assertCount(0, $result['skipped']);
        $this->assertSame(4, CronRun::query()->count());
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('last_status'));
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_BADGE_AUTO_AWARD)->value('last_status'));
    }

    public function testFailedTaskIncrementsFailureCounterAndRunHistory(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_BADGE_AUTO_AWARD, 'Badge Auto Award', 5, true, $now);

        $badge = $this->createMock(BadgeService::class);
        $badge->expects($this->once())->method('runAutoGrant')->willThrowException(new \RuntimeException('badge auto award failed'));

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $badge,
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-2']);

        $this->assertCount(1, $result['failed']);
        $this->assertSame('failed', CronTask::query()->where('task_key', CronSchedulerService::TASK_BADGE_AUTO_AWARD)->value('last_status'));
        $this->assertSame(1, (int) CronTask::query()->where('task_key', CronSchedulerService::TASK_BADGE_AUTO_AWARD)->value('consecutive_failures'));
        $this->assertSame('failed', CronRun::query()->where('task_key', CronSchedulerService::TASK_BADGE_AUTO_AWARD)->value('status'));
    }

    public function testManualRunAllowsDisabledTask(): void
    {
        $this->seedTask(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'Leaderboard Refresh', 10, false, null);

        $leaderboard = $this->createMock(LeaderboardService::class);
        $leaderboard->expects($this->once())->method('rebuildCache')->with('admin-manual')->willReturn([
            'generated_at' => '2026-04-10T00:00:00Z',
            'expires_at' => '2026-04-10T00:10:00Z',
            'global' => [],
            'regions' => [],
            'schools' => [],
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $leaderboard,
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'admin_manual', ['request_id' => 'req-3']);

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['next_run_at']);
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_LEADERBOARD_REFRESH)->value('last_status'));
        $this->assertSame('success', CronRun::query()->where('task_key', CronSchedulerService::TASK_LEADERBOARD_REFRESH)->value('status'));
    }

    public function testLockedTaskIsSkipped(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $now, [
            'lock_token' => 'existing-lock',
            'locked_at' => $now,
        ]);

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->never())->method('runSlaSweep');

        $service = $this->makeService(
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-4']);

        $this->assertCount(1, $result['skipped']);
        $this->assertSame('task_locked', $result['skipped'][0]['error_message']);
        $this->assertSame('skipped', CronRun::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('status'));
    }

    public function testUpdateTaskDisablesFutureRunWithoutClearingActiveLock(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $now, [
            'lock_token' => 'existing-lock',
            'locked_at' => $now,
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->updateTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, ['enabled' => false]);

        $this->assertFalse($result['enabled']);
        $this->assertNull($result['next_run_at']);
        $this->assertFalse($result['is_due']);
        $this->assertSame('existing-lock', CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('lock_token'));
    }

    public function testUpdateTaskRejectsNonIntegerInterval(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('interval_minutes must be an integer');

        $service->updateTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, ['interval_minutes' => '1.9']);
    }

    public function testUpdateTaskAllowsDisablingUnregisteredTask(): void
    {
        CronTask::query()->create([
            'task_key' => 'legacy_removed_task',
            'task_name' => 'Legacy Removed Task',
            'description' => 'Legacy',
            'interval_minutes' => 5,
            'enabled' => true,
            'next_run_at' => $this->now(),
            'last_status' => 'idle',
            'consecutive_failures' => 0,
            'settings_json' => '{}',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->updateTask('legacy_removed_task', ['enabled' => false]);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['is_registered']);
        $this->assertNull($result['next_run_at']);
    }

    public function testUpdateTaskRejectsUnsupportedFieldsForUnregisteredTaskAsValidationError(): void
    {
        CronTask::query()->create([
            'task_key' => 'legacy_removed_task',
            'task_name' => 'Legacy Removed Task',
            'description' => 'Legacy',
            'interval_minutes' => 5,
            'enabled' => true,
            'next_run_at' => $this->now(),
            'last_status' => 'idle',
            'consecutive_failures' => 0,
            'settings_json' => '{}',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unregistered cron tasks can only be disabled');

        $service->updateTask('legacy_removed_task', [
            'enabled' => false,
            'interval_minutes' => 15,
        ]);
    }

    public function testUpdateTaskKeepsNextRunWhenOnlySettingsChange(): void
    {
        $nextRunAt = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $nextRunAt, [
            'settings_json' => '{"foo":"bar"}',
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->updateTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, [
            'settings' => ['foo' => 'baz'],
        ]);

        $this->assertSame($nextRunAt, $result['next_run_at']);
        $this->assertSame($nextRunAt, CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('next_run_at'));
    }

    public function testSuccessfulRunUsesLatestTaskSettingsForNextRunAt(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'Leaderboard Refresh', 10, true, $now);

        $leaderboard = $this->createMock(LeaderboardService::class);
        $leaderboard->expects($this->once())->method('rebuildCache')->with('admin-manual')->willReturn([
            'generated_at' => '2026-04-10T00:00:00Z',
            'expires_at' => '2026-04-10T00:10:00Z',
            'global' => [],
            'regions' => [],
            'schools' => [],
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $leaderboard,
            $this->createMock(StreakLeaderboardService::class)
        );

        $service->updateTask(CronSchedulerService::TASK_LEADERBOARD_REFRESH, ['interval_minutes' => 30]);
        $result = $service->runTaskNow(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'admin_manual', ['request_id' => 'req-5']);

        $this->assertSame('success', $result['status']);
        $this->assertNotNull($result['next_run_at']);
        $this->assertSame($result['next_run_at'], CronTask::query()->where('task_key', CronSchedulerService::TASK_LEADERBOARD_REFRESH)->value('next_run_at'));
    }

    public function testNextRunAtUpdateDoesNotOverrideNewLock(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'Leaderboard Refresh', 10, true, $now);

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_task_release_relock');
        self::$capsule->getConnection()->statement("
            CREATE TRIGGER cron_task_release_relock
            AFTER UPDATE ON cron_tasks
            WHEN NEW.task_key = '" . CronSchedulerService::TASK_LEADERBOARD_REFRESH . "'
              AND OLD.lock_token IS NOT NULL
              AND NEW.lock_token IS NULL
            BEGIN
                UPDATE cron_tasks
                SET lock_token = 'new-lock',
                    locked_at = '" . $this->now() . "'
                WHERE task_key = '" . CronSchedulerService::TASK_LEADERBOARD_REFRESH . "';
            END;
        ");

        $leaderboard = $this->createMock(LeaderboardService::class);
        $leaderboard->expects($this->once())->method('rebuildCache')->with('admin-manual')->willReturn([
            'generated_at' => '2026-04-10T00:00:00Z',
            'expires_at' => '2026-04-10T00:10:00Z',
            'global' => [],
            'regions' => [],
            'schools' => [],
        ]);

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $leaderboard,
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_LEADERBOARD_REFRESH, 'admin_manual', ['request_id' => 'req-6']);

        $this->assertSame('success', $result['status']);
        $this->assertSame($now, $result['next_run_at']);
        $this->assertSame($now, CronTask::query()->where('task_key', CronSchedulerService::TASK_LEADERBOARD_REFRESH)->value('next_run_at'));
        $this->assertSame('new-lock', CronTask::query()->where('task_key', CronSchedulerService::TASK_LEADERBOARD_REFRESH)->value('lock_token'));

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_task_release_relock');
    }

    public function testDurationMillisecondsCapturesSubSecondExecution(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturnCallback(function () {
            usleep(20000);
            return ['processed' => 1, 'breached' => 0, 'rerouted' => 0];
        });

        $service = $this->makeService(
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'admin_manual', ['request_id' => 'req-7']);

        $this->assertGreaterThan(0, $result['duration_ms']);
        $this->assertGreaterThan(0, (int) CronRun::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('duration_ms'));
    }

    public function testNextRunUpdateFailureDoesNotFlipSuccessfulRun(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $now);

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_task_next_run_fail');
        self::$capsule->getConnection()->statement("
            CREATE TRIGGER cron_task_next_run_fail
            BEFORE UPDATE ON cron_tasks
            WHEN NEW.task_key = '" . CronSchedulerService::TASK_SUPPORT_SLA_SWEEP . "'
              AND NEW.next_run_at IS NOT OLD.next_run_at
            BEGIN
                SELECT RAISE(FAIL, 'next run update failed');
            END;
        ");

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturn(['processed' => 1, 'breached' => 0, 'rerouted' => 0]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'admin_manual', ['request_id' => 'req-13']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('last_status'));

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_task_next_run_fail');
    }

    public function testListRunsAllowsHistoricalUnknownTaskKeyFilter(): void
    {
        CronRun::query()->create([
            'task_key' => 'legacy_removed_task',
            'trigger_source' => 'legacy_endpoint',
            'request_id' => 'req-legacy',
            'status' => 'success',
            'started_at' => $this->now(),
            'finished_at' => $this->now(),
            'duration_ms' => 12,
            'result_json' => '{}',
            'error_message' => null,
            'created_at' => $this->now(),
        ]);

        $service = $this->makeService(
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->listRuns(['task_key' => 'legacy_removed_task']);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('legacy_removed_task', $result['items'][0]['task_key']);
    }

    public function testCompletionAuditFailureDoesNotFlipSuccessfulRun(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturn(['processed' => 1, 'breached' => 0, 'rerouted' => 0]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturnCallback(function (string $action) {
            if ($action === 'cron_task_run_completed') {
                throw new \RuntimeException('audit_failed');
            }
            return true;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $this->createMock(ErrorLogService::class),
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'admin_manual', ['request_id' => 'req-8']);

        $this->assertSame('success', $result['status']);
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('last_status'));
        $this->assertSame('success', CronRun::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('status'));
    }

    public function testRunHistoryPersistenceFailureDoesNotFlipSuccessfulRun(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturn(['processed' => 1, 'breached' => 0, 'rerouted' => 0]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail');
        self::$capsule->getConnection()->statement("
            CREATE TRIGGER cron_run_insert_fail
            BEFORE INSERT ON cron_runs
            WHEN NEW.task_key = '" . CronSchedulerService::TASK_SUPPORT_SLA_SWEEP . "'
            BEGIN
                SELECT RAISE(FAIL, 'cron run insert failed');
            END;
        ");

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $this->createMock(ErrorLogService::class),
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'admin_manual', ['request_id' => 'req-9']);

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['run_id']);
        $this->assertSame('success', CronTask::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('last_status'));

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail');
    }

    public function testFailureExceptionLoggingFailureDoesNotAbortBatch(): void
    {
        $this->seedTask(CronSchedulerService::TASK_BADGE_AUTO_AWARD, 'Badge Auto Award', 5, true, $this->now());

        $badge = $this->createMock(BadgeService::class);
        $badge->expects($this->once())->method('runAutoGrant')->willThrowException(new \RuntimeException('badge_failed'));

        $errorLogService = $this->createMock(ErrorLogService::class);
        $errorLogService->expects($this->once())->method('logException')->willThrowException(new \RuntimeException('error log failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $this->createMock(AuditLogService::class),
            $errorLogService,
            $this->createMock(SupportRoutingEngineService::class),
            $badge,
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-14']);

        $this->assertCount(1, $result['failed']);
        $this->assertSame('failed', $result['failed'][0]['status']);
    }

    public function testFailedRunHistoryPersistenceFailureDoesNotAbortBatch(): void
    {
        $this->seedTask(CronSchedulerService::TASK_BADGE_AUTO_AWARD, 'Badge Auto Award', 5, true, $this->now());

        $badge = $this->createMock(BadgeService::class);
        $badge->expects($this->once())->method('runAutoGrant')->willThrowException(new \RuntimeException('badge_failed'));

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail_failed');
        self::$capsule->getConnection()->statement("
            CREATE TRIGGER cron_run_insert_fail_failed
            BEFORE INSERT ON cron_runs
            WHEN NEW.task_key = '" . CronSchedulerService::TASK_BADGE_AUTO_AWARD . "'
            BEGIN
                SELECT RAISE(FAIL, 'cron failed run insert failed');
            END;
        ");

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(SupportRoutingEngineService::class),
            $badge,
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-10']);

        $this->assertCount(1, $result['failed']);
        $this->assertNull($result['failed'][0]['run_id']);
        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail_failed');
    }

    public function testSkippedRunPersistenceFailureDoesNotAbortBatch(): void
    {
        $now = $this->now();
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $now, [
            'lock_token' => 'existing-lock',
            'locked_at' => $now,
        ]);

        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail_skipped');
        self::$capsule->getConnection()->statement("
            CREATE TRIGGER cron_run_insert_fail_skipped
            BEFORE INSERT ON cron_runs
            WHEN NEW.task_key = '" . CronSchedulerService::TASK_SUPPORT_SLA_SWEEP . "'
            BEGIN
                SELECT RAISE(FAIL, 'cron skipped run insert failed');
            END;
        ");

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(SupportRoutingEngineService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-11']);

        $this->assertCount(1, $result['skipped']);
        $this->assertNull($result['skipped'][0]['run_id']);
        self::$capsule->getConnection()->statement('DROP TRIGGER IF EXISTS cron_run_insert_fail_skipped');
    }

    public function testBatchAuditFailureDoesNotAbortRunDueTasks(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturn(['processed' => 1, 'breached' => 0, 'rerouted' => 0]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturnCallback(function (string $action) {
            if ($action === 'cron_scheduler_batch_completed') {
                throw new \RuntimeException('batch_audit_failed');
            }
            return true;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $this->createMock(ErrorLogService::class),
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runDueTasks('cron_endpoint', ['request_id' => 'req-12']);

        $this->assertCount(1, $result['executed']);
    }

    public function testStaleCompletionIsRecordedAsFailureInsteadOfSuccess(): void
    {
        $this->seedTask(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'Support SLA Sweep', 1, true, $this->now());

        $support = $this->createMock(SupportRoutingEngineService::class);
        $support->expects($this->once())->method('runSlaSweep')->willReturnCallback(function (): array {
            CronTask::query()
                ->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)
                ->update([
                    'lock_token' => 'stolen-lock',
                    'locked_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);

            return ['processed' => 1, 'breached' => 0, 'rerouted' => 0];
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $service = new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $support,
            $this->createMock(BadgeService::class),
            $this->createMock(LeaderboardService::class),
            $this->createMock(StreakLeaderboardService::class)
        );

        $result = $service->runTaskNow(CronSchedulerService::TASK_SUPPORT_SLA_SWEEP, 'admin_manual', ['request_id' => 'req-stale-1']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('task_lock_lost', $result['error_message']);
        $this->assertSame('failed', CronRun::query()->where('task_key', CronSchedulerService::TASK_SUPPORT_SLA_SWEEP)->value('status'));
    }

    private function seedTask(string $taskKey, string $taskName, int $intervalMinutes, bool $enabled, ?string $nextRunAt, array $overrides = []): void
    {
        CronTask::query()->create(array_merge([
            'task_key' => $taskKey,
            'task_name' => $taskName,
            'description' => $taskName,
            'interval_minutes' => $intervalMinutes,
            'enabled' => $enabled,
            'next_run_at' => $nextRunAt,
            'last_status' => 'idle',
            'consecutive_failures' => 0,
            'settings_json' => '{}',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ], $overrides));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    private function makeService(
        SupportRoutingEngineService $support,
        BadgeService $badge,
        LeaderboardService $leaderboard,
        StreakLeaderboardService $streak
    ): CronSchedulerService {
        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        return new CronSchedulerService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            $support,
            $badge,
            $leaderboard,
            $streak
        );
    }
}
