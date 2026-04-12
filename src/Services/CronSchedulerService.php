<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\CronRun;
use CarbonTrack\Models\CronTask;
use CarbonTrack\Support\SyntheticRequestFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;

class CronSchedulerService
{
    public const TASK_SUPPORT_SLA_SWEEP = 'support_sla_sweep';
    public const TASK_BADGE_AUTO_AWARD = 'badge_auto_award';
    public const TASK_LEADERBOARD_REFRESH = 'leaderboard_refresh';
    public const TASK_STREAK_LEADERBOARD_REFRESH = 'streak_leaderboard_refresh';

    private const LOCK_TIMEOUT_SECONDS = 600;
    private const RUN_STATUS_SUCCESS = 'success';
    private const RUN_STATUS_FAILED = 'failed';
    private const RUN_STATUS_SKIPPED = 'skipped';
    private const TASK_STATUS_IDLE = 'idle';
    private const TASK_STATUS_RUNNING = 'running';
    private const TASK_STATUS_SUCCESS = 'success';
    private const TASK_STATUS_FAILED = 'failed';
    private const VALID_TRIGGER_SOURCES = ['cron_endpoint', 'legacy_endpoint', 'admin_manual'];
    private const STALE_COMPLETION_ERROR = 'task_lock_lost';
    private const VALID_TASK_STATUSES = [
        self::TASK_STATUS_IDLE,
        self::TASK_STATUS_RUNNING,
        self::TASK_STATUS_SUCCESS,
        self::TASK_STATUS_FAILED,
    ];
    private const VALID_RUN_STATUSES = [
        self::RUN_STATUS_SUCCESS,
        self::RUN_STATUS_FAILED,
        self::RUN_STATUS_SKIPPED,
    ];

    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService,
        private SupportRoutingEngineService $supportRoutingEngineService,
        private BadgeService $badgeService,
        private LeaderboardService $leaderboardService,
        private StreakLeaderboardService $streakLeaderboardService
    ) {
    }

    public function listTasks(): array
    {
        $now = $this->now();

        return array_map(
            fn (CronTask $task): array => $this->formatTask($task, $now),
            CronTask::query()->orderBy('task_key')->get()->all()
        );
    }

    public function updateTask(string $taskKey, array $payload): array
    {
        $taskKey = $this->normalizeLookupTaskKey($taskKey);
        $task = $this->findTask($taskKey);
        if ($task === null) {
            throw new \RuntimeException('Cron task not found');
        }

        $isRegisteredTask = $this->isRegisteredTaskKey($taskKey);
        if (!$isRegisteredTask) {
            $this->assertOnlyDisableForUnregisteredTask($payload);
        }

        $changed = false;
        $scheduleChanged = false;
        if (array_key_exists('enabled', $payload)) {
            $task->enabled = $this->normalizeBoolean($payload['enabled'], 'enabled');
            $changed = true;
            $scheduleChanged = true;
        }

        if (array_key_exists('interval_minutes', $payload)) {
            $task->interval_minutes = $this->normalizeIntervalMinutes($payload['interval_minutes']);
            $changed = true;
            $scheduleChanged = true;
        }

        if (array_key_exists('settings', $payload)) {
            $settings = $payload['settings'];
            if ($settings !== null && !is_array($settings)) {
                throw new \InvalidArgumentException('settings must be an object');
            }
            $task->settings_json = $this->encodeJson($settings ?? []);
            $changed = true;
        }

        if (!$changed) {
            throw new \InvalidArgumentException('No cron task fields provided');
        }

        $now = $this->now();
        if ($scheduleChanged) {
            if ($task->enabled) {
                $task->next_run_at = $this->addMinutes($now, (int) $task->interval_minutes);
            } else {
                $task->next_run_at = null;
            }
        }

        $task->updated_at = $now;
        $task->save();

        return $this->formatTask($task, $now);
    }

    public function listRuns(array $query = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));

        $runsQuery = CronRun::query()->orderByDesc('id');
        if (!empty($query['task_key'])) {
            $taskKey = strtolower(trim((string) $query['task_key']));
            if ($taskKey === '') {
                throw new \InvalidArgumentException('Invalid cron task key filter');
            }
            $runsQuery->where('task_key', $taskKey);
        }
        if (!empty($query['status'])) {
            $status = strtolower(trim((string) $query['status']));
            if (!in_array($status, self::VALID_RUN_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid cron run status');
            }
            $runsQuery->where('status', $status);
        }
        if (!empty($query['trigger_source'])) {
            $triggerSource = strtolower(trim((string) $query['trigger_source']));
            if (!in_array($triggerSource, self::VALID_TRIGGER_SOURCES, true)) {
                throw new \InvalidArgumentException('Invalid cron trigger source');
            }
            $runsQuery->where('trigger_source', $triggerSource);
        }

        $total = (clone $runsQuery)->count();
        $items = $runsQuery
            ->forPage($page, $limit)
            ->get()
            ->map(fn (CronRun $run): array => $this->formatRun($run))
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ],
        ];
    }

    public function runDueTasks(string $triggerSource = 'cron_endpoint', array $context = []): array
    {
        $triggerSource = $this->normalizeTriggerSource($triggerSource);
        $now = $this->now();
        $dueTasks = CronTask::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->orderBy('task_key')
            ->get()
            ->all();

        $response = [
            'triggered_at' => $now,
            'due' => array_map(static fn (CronTask $task): string => (string) $task->task_key, $dueTasks),
            'executed' => [],
            'failed' => [],
            'skipped' => [],
        ];

        foreach ($dueTasks as $task) {
            $runResult = $this->runTaskInternal((string) $task->task_key, false, $triggerSource, $context);
            if ($runResult['status'] === self::RUN_STATUS_SUCCESS) {
                $response['executed'][] = $runResult;
            } elseif ($runResult['status'] === self::RUN_STATUS_FAILED) {
                $response['failed'][] = $runResult;
            } else {
                $response['skipped'][] = $runResult;
            }
        }

        try {
            $this->auditLogService->logSystemEvent('cron_scheduler_batch_completed', 'cron_scheduler', [
                'status' => !empty($response['failed']) || !empty($response['skipped']) ? 'failed' : 'success',
                'request_method' => 'SYSTEM',
                'endpoint' => '/cron/run',
                'request_id' => $context['request_id'] ?? null,
                'request_data' => [
                    'trigger_source' => $triggerSource,
                    'due_count' => count($response['due']),
                    'executed_count' => count($response['executed']),
                    'failed_count' => count($response['failed']),
                    'skipped_count' => count($response['skipped']),
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->logNonCriticalPostRunFailure(
                'Cron scheduler batch audit logging failed',
                'batch',
                $triggerSource,
                $context,
                $exception,
                '/cron/run',
                'cron_scheduler_batch_logging_failed'
            );
        }

        return $response;
    }

    public function runTaskNow(string $taskKey, string $triggerSource = 'admin_manual', array $context = []): array
    {
        $taskKey = $this->normalizeLookupTaskKey($taskKey);
        $task = $this->findTask($taskKey);
        if ($task === null) {
            throw new \RuntimeException('Cron task not found');
        }
        $this->ensureRegisteredTaskKey($taskKey);

        return $this->runTaskInternal($taskKey, true, $this->normalizeTriggerSource($triggerSource), $context);
    }

    private function runTaskInternal(string $taskKey, bool $forceRun, string $triggerSource, array $context): array
    {
        $task = $this->findTask($taskKey);
        if ($task === null) {
            throw new \RuntimeException('Cron task not found');
        }

        $lockNow = $this->now();
        if ($this->isFreshLockActive($task, $lockNow)) {
            return $this->recordSkippedRun($task, $triggerSource, $context, 'task_locked');
        }

        $lockToken = $this->generateLockToken();
        if (!$this->acquireLock($taskKey, $lockToken, $lockNow, $forceRun)) {
            return $this->recordSkippedRun($task, $triggerSource, $context, $this->determineSkipReason($task, $forceRun, $lockNow));
        }

        $startedAt = $lockNow;
        $startedAtMicro = microtime(true);
        try {
            $rawResult = $this->executeTaskHandler($taskKey, $triggerSource);
            $result = $this->normalizeTaskResult($taskKey, $rawResult);
            $finishedAt = $this->now();
            $durationMs = $this->diffMilliseconds($startedAtMicro, microtime(true));
            $freshTask = $this->findTask($taskKey);
            $nextRunAt = null;
            if ($freshTask?->enabled) {
                $nextRunAt = $freshTask->next_run_at;
                $nextRunAt = $this->addMinutes($finishedAt, (int) $freshTask->interval_minutes);
            }

            if (!$this->finalizeTaskRun($taskKey, $lockToken, [
                'last_finished_at' => $finishedAt,
                'last_status' => self::TASK_STATUS_SUCCESS,
                'last_error' => null,
                'last_duration_ms' => $durationMs,
                'consecutive_failures' => 0,
                'next_run_at' => $freshTask?->enabled ? $nextRunAt : null,
                'updated_at' => $finishedAt,
            ], $triggerSource, $context)) {
                return $this->recordStaleCompletion($task, $triggerSource, $context, $startedAt, $finishedAt, $durationMs, $result);
            }

            $freshTask = $this->findTask($taskKey);
            $nextRunAt = $freshTask?->next_run_at;

            $runId = null;
            try {
                $run = CronRun::create([
                    'task_key' => $taskKey,
                    'trigger_source' => $triggerSource,
                    'request_id' => $context['request_id'] ?? null,
                    'status' => self::RUN_STATUS_SUCCESS,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $durationMs,
                    'result_json' => $this->encodeJson($result),
                    'error_message' => null,
                ]);
                $runId = (int) $run->id;
            } catch (\Throwable $persistenceException) {
                $this->logNonCriticalPostRunFailure('Cron task run-history persistence failed', $taskKey, $triggerSource, $context, $persistenceException);
            }

            try {
                $this->auditLogService->logSystemEvent('cron_task_run_completed', 'cron_scheduler', [
                    'status' => 'success',
                    'request_method' => 'SYSTEM',
                    'endpoint' => '/internal/cron/' . $taskKey,
                    'request_id' => $context['request_id'] ?? null,
                    'request_data' => [
                        'task_key' => $taskKey,
                        'trigger_source' => $triggerSource,
                        'duration_ms' => $durationMs,
                    ],
                    'new_data' => $result,
                ]);
            } catch (\Throwable $loggingException) {
                $this->logNonCriticalPostRunFailure(
                    'Cron task completion audit logging failed',
                    $taskKey,
                    $triggerSource,
                    $context,
                    $loggingException
                );
            }

            return [
                'task_key' => $taskKey,
                'task_name' => $task->task_name,
                'status' => self::RUN_STATUS_SUCCESS,
                'run_id' => $runId,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'result' => $result,
                'next_run_at' => $nextRunAt,
            ];
        } catch (\Throwable $exception) {
            $finishedAt = $this->now();
            $durationMs = $this->diffMilliseconds($startedAtMicro, microtime(true));
            $errorMessage = trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Unknown cron task error';
            $freshTask = $this->findTask($taskKey);
            $nextRunAt = null;
            if ($freshTask?->enabled) {
                $nextRunAt = $freshTask->next_run_at;
                $nextRunAt = $this->addMinutes($finishedAt, (int) $freshTask->interval_minutes);
            }

            if (!$this->finalizeTaskRun($taskKey, $lockToken, [
                'last_finished_at' => $finishedAt,
                'last_status' => self::TASK_STATUS_FAILED,
                'last_error' => $errorMessage,
                'last_duration_ms' => $durationMs,
                'consecutive_failures' => (int) ($task->consecutive_failures ?? 0) + 1,
                'next_run_at' => $freshTask?->enabled ? $nextRunAt : null,
                'updated_at' => $finishedAt,
            ], $triggerSource, $context)) {
                return $this->recordStaleCompletion(
                    $task,
                    $triggerSource,
                    $context,
                    $startedAt,
                    $finishedAt,
                    $durationMs,
                    ['reason' => self::STALE_COMPLETION_ERROR, 'original_error' => $errorMessage],
                    $exception
                );
            }

            $freshTask = $this->findTask($taskKey);
            $nextRunAt = $freshTask?->next_run_at;

            $runId = null;
            try {
                $run = CronRun::create([
                    'task_key' => $taskKey,
                    'trigger_source' => $triggerSource,
                    'request_id' => $context['request_id'] ?? null,
                    'status' => self::RUN_STATUS_FAILED,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $durationMs,
                    'result_json' => $this->encodeJson([]),
                    'error_message' => $errorMessage,
                ]);
                $runId = (int) $run->id;
            } catch (\Throwable $persistenceException) {
                $this->logNonCriticalPostRunFailure(
                    'Cron task failed-run persistence failed',
                    $taskKey,
                    $triggerSource,
                    $context,
                    $persistenceException
                );
            }

            $this->logTaskException($taskKey, $triggerSource, $context, $exception);
            try {
                $this->auditLogService->logSystemEvent('cron_task_run_failed', 'cron_scheduler', [
                    'status' => 'failed',
                    'request_method' => 'SYSTEM',
                    'endpoint' => '/internal/cron/' . $taskKey,
                    'request_id' => $context['request_id'] ?? null,
                    'request_data' => [
                        'task_key' => $taskKey,
                        'trigger_source' => $triggerSource,
                        'duration_ms' => $durationMs,
                    ],
                    'data' => ['error' => $errorMessage],
                ]);
            } catch (\Throwable $loggingException) {
                $this->logNonCriticalPostRunFailure(
                    'Cron task failure audit logging failed',
                    $taskKey,
                    $triggerSource,
                    $context,
                    $loggingException
                );
            }

            return [
                'task_key' => $taskKey,
                'task_name' => $task->task_name,
                'status' => self::RUN_STATUS_FAILED,
                'run_id' => $runId,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'result' => [],
                'error_message' => $errorMessage,
                'next_run_at' => $nextRunAt,
            ];
        }
    }

    private function recordSkippedRun(CronTask $task, string $triggerSource, array $context, string $reason): array
    {
        $now = $this->now();
        $runId = null;
        try {
            $run = CronRun::create([
                'task_key' => (string) $task->task_key,
                'trigger_source' => $triggerSource,
                'request_id' => $context['request_id'] ?? null,
                'status' => self::RUN_STATUS_SKIPPED,
                'started_at' => $now,
                'finished_at' => $now,
                'duration_ms' => 0,
                'result_json' => $this->encodeJson(['reason' => $reason]),
                'error_message' => $reason,
            ]);
            $runId = (int) $run->id;
        } catch (\Throwable $persistenceException) {
            $this->logNonCriticalPostRunFailure(
                'Cron task skipped-run persistence failed',
                (string) $task->task_key,
                $triggerSource,
                $context,
                $persistenceException
            );
        }

        try {
            $this->auditLogService->logSystemEvent('cron_task_run_skipped', 'cron_scheduler', [
                'status' => 'skipped',
                'request_method' => 'SYSTEM',
                'endpoint' => '/internal/cron/' . $task->task_key,
                'request_id' => $context['request_id'] ?? null,
                'request_data' => [
                    'task_key' => $task->task_key,
                    'trigger_source' => $triggerSource,
                    'reason' => $reason,
                ],
            ]);
        } catch (\Throwable $loggingException) {
            $this->logNonCriticalPostRunFailure(
                'Cron task skipped audit logging failed',
                (string) $task->task_key,
                $triggerSource,
                $context,
                $loggingException
            );
        }

        return [
            'task_key' => (string) $task->task_key,
            'task_name' => (string) $task->task_name,
            'status' => self::RUN_STATUS_SKIPPED,
            'run_id' => $runId,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'result' => [],
            'error_message' => $reason,
            'next_run_at' => $task->next_run_at,
        ];
    }

    private function acquireLock(string $taskKey, string $lockToken, string $now, bool $forceRun): bool
    {
        $staleBefore = $this->addSeconds($now, -self::LOCK_TIMEOUT_SECONDS);
        $sql = '
            UPDATE cron_tasks
            SET
                lock_token = :lock_token,
                locked_at = :locked_at,
                last_started_at = :last_started_at,
                last_status = :last_status,
                updated_at = :updated_at
            WHERE task_key = :task_key
              AND (
                    lock_token IS NULL
                    OR locked_at IS NULL
                    OR locked_at < :stale_before
                  )
        ';
        if ($forceRun) {
            $params = [
                'lock_token' => $lockToken,
                'locked_at' => $now,
                'last_started_at' => $now,
                'last_status' => self::TASK_STATUS_RUNNING,
                'updated_at' => $now,
                'task_key' => $taskKey,
                'stale_before' => $staleBefore,
            ];
        } else {
            $sql .= '
              AND enabled = 1
              AND next_run_at IS NOT NULL
              AND next_run_at <= :now_value
            ';
            $params = [
                'lock_token' => $lockToken,
                'locked_at' => $now,
                'last_started_at' => $now,
                'last_status' => self::TASK_STATUS_RUNNING,
                'updated_at' => $now,
                'task_key' => $taskKey,
                'stale_before' => $staleBefore,
                'now_value' => $now,
            ];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    private function completeTaskRun(string $taskKey, string $lockToken, array $fields): bool
    {
        $set = [];
        $params = [
            'task_key' => $taskKey,
            'lock_token_match' => $lockToken,
        ];

        foreach ($fields as $field => $value) {
            $set[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }

        $sql = 'UPDATE cron_tasks SET ' . implode(', ', $set) . ' WHERE task_key = :task_key AND lock_token = :lock_token_match';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    private function finalizeTaskRun(
        string $taskKey,
        string $lockToken,
        array $fields,
        string $triggerSource = 'internal',
        array $context = []
    ): bool
    {
        try {
            return $this->completeTaskRun($taskKey, $lockToken, $fields + [
                'lock_token' => null,
                'locked_at' => null,
            ]);
        } catch (\Throwable $releaseException) {
            $this->logNonCriticalPostRunFailure(
                'Cron task release failed after completion',
                $taskKey,
                $triggerSource,
                $context,
                $releaseException
            );

            $fallbackFields = $fields;
            unset($fallbackFields['next_run_at']);

            return $this->completeTaskRun($taskKey, $lockToken, $fallbackFields);
        }
    }

    private function executeTaskHandler(string $taskKey, string $triggerSource): array
    {
        return match ($taskKey) {
            self::TASK_SUPPORT_SLA_SWEEP => $this->supportRoutingEngineService->runSlaSweep(),
            self::TASK_BADGE_AUTO_AWARD => $this->badgeService->runAutoGrant(),
            self::TASK_LEADERBOARD_REFRESH => $this->leaderboardService->rebuildCache($this->reasonForTrigger($triggerSource)),
            self::TASK_STREAK_LEADERBOARD_REFRESH => $this->streakLeaderboardService->rebuildCache($this->reasonForTrigger($triggerSource)),
            default => throw new \RuntimeException('Unsupported cron task'),
        };
    }

    private function reasonForTrigger(string $triggerSource): string
    {
        return match ($triggerSource) {
            'cron_endpoint' => 'cron',
            'legacy_endpoint' => 'legacy-endpoint',
            'admin_manual' => 'admin-manual',
            default => $triggerSource,
        };
    }

    private function normalizeTaskResult(string $taskKey, array $rawResult): array
    {
        return match ($taskKey) {
            self::TASK_SUPPORT_SLA_SWEEP => [
                'processed' => (int) ($rawResult['processed'] ?? 0),
                'breached' => (int) ($rawResult['breached'] ?? 0),
                'rerouted' => (int) ($rawResult['rerouted'] ?? 0),
            ],
            self::TASK_BADGE_AUTO_AWARD => [
                'awarded' => (int) ($rawResult['awarded'] ?? 0),
                'skipped' => (int) ($rawResult['skipped'] ?? 0),
                'badges' => (int) ($rawResult['badges'] ?? 0),
                'users' => (int) ($rawResult['users'] ?? 0),
            ],
            self::TASK_LEADERBOARD_REFRESH,
            self::TASK_STREAK_LEADERBOARD_REFRESH => [
                'generated_at' => $rawResult['generated_at'] ?? null,
                'expires_at' => $rawResult['expires_at'] ?? null,
                'global_count' => count($rawResult['global'] ?? []),
                'regions_count' => count($rawResult['regions'] ?? []),
                'schools_count' => count($rawResult['schools'] ?? []),
            ],
            default => $rawResult,
        };
    }

    private function formatTask(CronTask $task, string $now): array
    {
        $lockedAt = $this->normalizeDateValue($task->locked_at);
        $settings = $this->decodeJsonObject($task->settings_json) ?? [];

        return [
            'task_key' => (string) $task->task_key,
            'task_name' => (string) $task->task_name,
            'description' => $task->description,
            'is_registered' => $this->isRegisteredTaskKey((string) $task->task_key),
            'interval_minutes' => (int) ($task->interval_minutes ?? 0),
            'enabled' => (bool) $task->enabled,
            'next_run_at' => $task->next_run_at,
            'last_started_at' => $task->last_started_at,
            'last_finished_at' => $task->last_finished_at,
            'last_status' => $task->last_status,
            'last_error' => $task->last_error,
            'last_duration_ms' => $task->last_duration_ms !== null ? (int) $task->last_duration_ms : null,
            'consecutive_failures' => (int) ($task->consecutive_failures ?? 0),
            'locked_at' => $lockedAt,
            'settings' => $settings,
            'is_due' => (bool) $task->enabled
                && is_string($task->next_run_at)
                && $task->next_run_at !== ''
                && $task->next_run_at <= $now,
            'is_locked' => $lockedAt !== null && $lockedAt >= $this->addSeconds($now, -self::LOCK_TIMEOUT_SECONDS),
        ];
    }

    private function formatRun(CronRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'task_key' => (string) $run->task_key,
            'trigger_source' => (string) $run->trigger_source,
            'request_id' => $run->request_id,
            'status' => (string) $run->status,
            'started_at' => $run->started_at,
            'finished_at' => $run->finished_at,
            'duration_ms' => $run->duration_ms !== null ? (int) $run->duration_ms : null,
            'result' => $this->decodeJsonObject($run->result_json) ?? [],
            'error_message' => $run->error_message,
            'created_at' => $run->created_at,
        ];
    }

    private function findTask(string $taskKey): ?CronTask
    {
        return CronTask::query()->where('task_key', $taskKey)->first();
    }

    private function determineSkipReason(CronTask $task, bool $forceRun, string $now): string
    {
        if (!$forceRun && !$task->enabled) {
            return 'task_disabled';
        }
        if (!$forceRun && (!is_string($task->next_run_at) || $task->next_run_at === '' || $task->next_run_at > $now)) {
            return 'task_not_due';
        }
        return 'task_locked';
    }

    private function isFreshLockActive(CronTask $task, string $now): bool
    {
        if (!is_string($task->lock_token) || trim($task->lock_token) === '') {
            return false;
        }
        $lockedAt = $this->normalizeDateValue($task->locked_at);
        if ($lockedAt === null) {
            return false;
        }

        return $lockedAt >= $this->addSeconds($now, -self::LOCK_TIMEOUT_SECONDS);
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    private function normalizeTaskKey(string $taskKey): string
    {
        $normalized = strtolower(trim($taskKey));
        if ($normalized === '') {
            throw new \InvalidArgumentException('Cron task key is required');
        }
        return $normalized;
    }

    private function ensureRegisteredTaskKey(string $taskKey): void
    {
        if (!$this->isRegisteredTaskKey($taskKey)) {
            throw new \RuntimeException('Cron task not found');
        }
    }

    private function isRegisteredTaskKey(string $taskKey): bool
    {
        $definitions = $this->taskDefinitions();
        return isset($definitions[$taskKey]);
    }

    private function normalizeLookupTaskKey(string $taskKey): string
    {
        return $this->normalizeTaskKey($taskKey);
    }

    private function assertOnlyDisableForUnregisteredTask(array $payload): void
    {
        if (!array_key_exists('enabled', $payload)) {
            throw new \InvalidArgumentException('Unregistered cron tasks can only be disabled');
        }

        if ($this->normalizeBoolean($payload['enabled'], 'enabled')) {
            throw new \InvalidArgumentException('Unregistered cron tasks can only be disabled');
        }

        if (array_key_exists('interval_minutes', $payload) || array_key_exists('settings', $payload)) {
            throw new \InvalidArgumentException('Unregistered cron tasks can only be disabled');
        }
    }

    private function normalizeTriggerSource(string $triggerSource): string
    {
        $normalized = strtolower(trim($triggerSource));
        if (!in_array($normalized, self::VALID_TRIGGER_SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid cron trigger source');
        }
        return $normalized;
    }

    private function normalizeBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['false', 'no', 'off'], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException($field . ' must be a boolean');
    }

    private function normalizeIntervalMinutes(mixed $value): int
    {
        if (is_int($value)) {
            $interval = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $interval = (int) trim($value);
        } else {
            throw new \InvalidArgumentException('interval_minutes must be an integer');
        }
        if ($interval < 1 || $interval > 1440) {
            throw new \InvalidArgumentException('interval_minutes must be between 1 and 1440');
        }

        return $interval;
    }

    private function taskDefinitions(): array
    {
        return [
            self::TASK_SUPPORT_SLA_SWEEP => [
                'task_name' => 'Support SLA Sweep',
                'description' => 'Inspect unresolved support tickets, update SLA status, and reroute escalated tickets.',
            ],
            self::TASK_BADGE_AUTO_AWARD => [
                'task_name' => 'Badge Auto Award',
                'description' => 'Evaluate active users against badge auto-grant rules and award newly qualified badges.',
            ],
            self::TASK_LEADERBOARD_REFRESH => [
                'task_name' => 'Leaderboard Refresh',
                'description' => 'Refresh the main points leaderboard cache for global, regional, and school rankings.',
            ],
            self::TASK_STREAK_LEADERBOARD_REFRESH => [
                'task_name' => 'Streak Leaderboard Refresh',
                'description' => 'Refresh the streak leaderboard cache for current and longest check-in streak rankings.',
            ],
        ];
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function decodeJsonObject(?string $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function logTaskException(string $taskKey, string $triggerSource, array $context, \Throwable $exception): void
    {
        $this->logger->error('Cron task execution failed', [
            'task_key' => $taskKey,
            'trigger_source' => $triggerSource,
            'error' => $exception->getMessage(),
        ]);

        try {
            $request = SyntheticRequestFactory::fromContext(
                '/internal/cron/' . $taskKey,
                'SYSTEM',
                is_string($context['request_id'] ?? null) ? (string) $context['request_id'] : null,
                [],
                $context + [
                    'task_key' => $taskKey,
                    'trigger_source' => $triggerSource,
                ],
                ['PHP_SAPI' => PHP_SAPI]
            );

            $this->errorLogService->logException($exception, $request, [
                'context_message' => 'cron_task_run_failed',
                'task_key' => $taskKey,
                'trigger_source' => $triggerSource,
            ]);
        } catch (\Throwable $loggingException) {
            $this->logger->warning('Cron task exception logging failed', [
                'task_key' => $taskKey,
                'trigger_source' => $triggerSource,
                'error' => $loggingException->getMessage(),
            ]);
        }
    }

    private function logNonCriticalPostRunFailure(
        string $message,
        string $taskKey,
        string $triggerSource,
        array $context,
        \Throwable $exception,
        ?string $endpoint = null,
        string $contextMessage = 'cron_task_post_run_recording_failed'
    ): void
    {
        $this->logger->warning($message, [
            'task_key' => $taskKey,
            'trigger_source' => $triggerSource,
            'error' => $exception->getMessage(),
        ]);

        try {
            $request = SyntheticRequestFactory::fromContext(
                $endpoint ?? '/internal/cron/' . $taskKey,
                'SYSTEM',
                is_string($context['request_id'] ?? null) ? (string) $context['request_id'] : null,
                [],
                $context + [
                    'task_key' => $taskKey,
                    'trigger_source' => $triggerSource,
                ],
                ['PHP_SAPI' => PHP_SAPI]
            );

            $this->errorLogService->logException($exception, $request, [
                'context_message' => $contextMessage,
                'task_key' => $taskKey,
                'trigger_source' => $triggerSource,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private function recordStaleCompletion(
        CronTask $task,
        string $triggerSource,
        array $context,
        string $startedAt,
        string $finishedAt,
        int $durationMs,
        array $result = [],
        ?\Throwable $exception = null
    ): array {
        $taskKey = (string) $task->task_key;
        $errorMessage = self::STALE_COMPLETION_ERROR;

        $this->logger->warning('Cron task completion aborted because lock ownership was lost', [
            'task_key' => $taskKey,
            'trigger_source' => $triggerSource,
            'request_id' => $context['request_id'] ?? null,
            'original_error' => $exception?->getMessage(),
        ]);

        if ($exception !== null) {
            $this->logTaskException($taskKey, $triggerSource, $context, $exception);
        }

        $runPayload = $result !== [] ? $result : ['reason' => $errorMessage];
        $runId = null;
        try {
            $run = CronRun::create([
                'task_key' => $taskKey,
                'trigger_source' => $triggerSource,
                'request_id' => $context['request_id'] ?? null,
                'status' => self::RUN_STATUS_FAILED,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'result_json' => $this->encodeJson($runPayload),
                'error_message' => $errorMessage,
            ]);
            $runId = (int) $run->id;
        } catch (\Throwable $persistenceException) {
            $this->logNonCriticalPostRunFailure(
                'Cron task stale-run persistence failed',
                $taskKey,
                $triggerSource,
                $context,
                $persistenceException
            );
        }

        try {
            $this->auditLogService->logSystemEvent('cron_task_run_failed', 'cron_scheduler', [
                'status' => 'failed',
                'request_method' => 'SYSTEM',
                'endpoint' => '/internal/cron/' . $taskKey,
                'request_id' => $context['request_id'] ?? null,
                'request_data' => [
                    'task_key' => $taskKey,
                    'trigger_source' => $triggerSource,
                    'duration_ms' => $durationMs,
                    'reason' => $errorMessage,
                ],
                'data' => [
                    'error' => $errorMessage,
                    'result' => $runPayload,
                ],
            ]);
        } catch (\Throwable $loggingException) {
            $this->logNonCriticalPostRunFailure(
                'Cron task stale-completion audit logging failed',
                $taskKey,
                $triggerSource,
                $context,
                $loggingException
            );
        }

        $freshTask = $this->findTask($taskKey);

        return [
            'task_key' => $taskKey,
            'task_name' => $task->task_name,
            'status' => self::RUN_STATUS_FAILED,
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'result' => $runPayload,
            'error_message' => $errorMessage,
            'next_run_at' => $freshTask?->next_run_at,
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    private function addMinutes(string $dateTime, int $minutes): string
    {
        return (new DateTimeImmutable($dateTime, new DateTimeZone('Asia/Shanghai')))
            ->modify(sprintf('+%d minutes', $minutes))
            ->format('Y-m-d H:i:s');
    }

    private function addSeconds(string $dateTime, int $seconds): string
    {
        $modifier = $seconds >= 0 ? '+' . $seconds : (string) $seconds;
        return (new DateTimeImmutable($dateTime, new DateTimeZone('Asia/Shanghai')))
            ->modify($modifier . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private function diffMilliseconds(float $startedAt, float $finishedAt): int
    {
        return (int) max(0, round(($finishedAt - $startedAt) * 1000));
    }

    private function generateLockToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            if (!function_exists('openssl_random_pseudo_bytes')) {
                throw new \RuntimeException('Unable to generate cron lock token');
            }

            $bytes = openssl_random_pseudo_bytes(16);
            if (!is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('Unable to generate cron lock token');
            }

            return bin2hex($bytes);
        }
    }
}
