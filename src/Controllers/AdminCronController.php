<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\ErrorLogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminCronController
{
    public function __construct(
        private CronSchedulerService $cronSchedulerService,
        private AuthService $authService,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
        private ErrorLogService $errorLogService
    ) {
    }

    public function listTasks(Request $request, Response $response): Response
    {
        try {
            $actor = $this->currentUser($request);
            $result = $this->cronSchedulerService->listTasks();
            $this->auditLogService->logAdminOperation('admin_cron_tasks_listed', $this->actorId($actor), 'admin_cron', [
                'table' => 'cron_tasks',
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
                'new_data' => ['count' => count($result)],
            ]);

            return $this->json($request, $response, ['success' => true, 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->error($request, $response, $exception, 'Failed to load cron tasks');
        }
    }

    public function updateTask(Request $request, Response $response, array $args): Response
    {
        try {
            $actor = $this->currentUser($request);
            $taskKey = $this->taskKey($args);
            $result = $this->cronSchedulerService->updateTask($taskKey, $this->body($request));

            $this->auditLogService->logAdminOperation('admin_cron_task_updated', $this->actorId($actor), 'admin_cron', [
                'table' => 'cron_tasks',
                'record_id' => null,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
                'request_data' => ['task_key' => $taskKey],
                'new_data' => $result,
            ]);

            return $this->json($request, $response, ['success' => true, 'data' => $result]);
        } catch (\RuntimeException $exception) {
            if ($this->isTaskNotFoundException($exception)) {
                return $this->json($request, $response, ['success' => false, 'message' => $exception->getMessage(), 'code' => 'CRON_TASK_NOT_FOUND'], 404);
            }
            return $this->error($request, $response, $exception, 'Failed to update cron task');
        } catch (\InvalidArgumentException $exception) {
            return $this->json($request, $response, ['success' => false, 'message' => $exception->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $exception) {
            return $this->error($request, $response, $exception, 'Failed to update cron task');
        }
    }

    public function listRuns(Request $request, Response $response): Response
    {
        try {
            $actor = $this->currentUser($request);
            $result = $this->cronSchedulerService->listRuns($request->getQueryParams());

            $this->auditLogService->logAdminOperation('admin_cron_runs_listed', $this->actorId($actor), 'admin_cron', [
                'table' => 'cron_runs',
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
                'request_data' => $request->getQueryParams(),
                'new_data' => ['count' => count($result['items'] ?? [])],
            ]);

            return $this->json($request, $response, ['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json($request, $response, ['success' => false, 'message' => $exception->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $exception) {
            return $this->error($request, $response, $exception, 'Failed to load cron runs');
        }
    }

    public function runTask(Request $request, Response $response, array $args): Response
    {
        try {
            $actor = $this->currentUser($request);
            $taskKey = $this->taskKey($args);
            $result = $this->cronSchedulerService->runTaskNow($taskKey, 'admin_manual', [
                'request_id' => $request->getAttribute('request_id'),
                'admin_id' => $this->actorId($actor),
            ]);

            $this->auditLogService->logAdminOperation('admin_cron_task_triggered', $this->actorId($actor), 'admin_cron', [
                'table' => 'cron_tasks',
                'request_id' => $request->getAttribute('request_id'),
                'status' => ($result['status'] ?? null) === 'success' ? 'success' : 'failed',
                'request_data' => ['task_key' => $taskKey],
                'new_data' => $result,
            ]);

            if (($result['status'] ?? null) !== 'success') {
                $status = ($result['status'] ?? null) === 'skipped' ? 409 : 503;

                return $this->json($request, $response, [
                    'success' => false,
                    'message' => $result['error_message'] ?? 'Cron task did not complete successfully',
                    'code' => 'CRON_TASK_FAILED',
                    'data' => $result,
                ], $status);
            }

            return $this->json($request, $response, ['success' => true, 'data' => $result]);
        } catch (\RuntimeException $exception) {
            if ($this->isTaskNotFoundException($exception)) {
                return $this->json($request, $response, ['success' => false, 'message' => $exception->getMessage(), 'code' => 'CRON_TASK_NOT_FOUND'], 404);
            }
            return $this->error($request, $response, $exception, 'Failed to trigger cron task');
        } catch (\InvalidArgumentException $exception) {
            return $this->json($request, $response, ['success' => false, 'message' => $exception->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $exception) {
            return $this->error($request, $response, $exception, 'Failed to trigger cron task');
        }
    }

    private function currentUser(Request $request): array
    {
        $user = $this->authService->getCurrentUser($request);
        return is_array($user) ? $user : [];
    }

    private function actorId(array $actor): ?int
    {
        return isset($actor['id']) && is_numeric($actor['id']) ? (int) $actor['id'] : null;
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function taskKey(array $args): string
    {
        $taskKey = isset($args['taskKey']) ? trim((string) $args['taskKey']) : '';
        if ($taskKey === '') {
            throw new \InvalidArgumentException('Invalid cron task key');
        }
        return $taskKey;
    }

    private function isTaskNotFoundException(\RuntimeException $exception): bool
    {
        return $exception->getMessage() === 'Cron task not found';
    }

    private function error(Request $request, Response $response, \Throwable $exception, string $message): Response
    {
        $this->logger->error($message, ['error' => $exception->getMessage()]);
        try {
            $this->errorLogService->logException($exception, $request, ['context_message' => $message]);
        } catch (\Throwable $loggingError) {
            $this->logger->error('Admin cron logging failed', ['error' => $loggingError->getMessage()]);
        }

        return $this->json($request, $response, ['success' => false, 'message' => $message, 'code' => 'INTERNAL_ERROR'], 500);
    }

    private function json(Request $request, Response $response, array $payload, int $status = 200): Response
    {
        if ($status >= 400 && !array_key_exists('request_id', $payload)) {
            $payload['request_id'] = $request->getAttribute('request_id');
        }
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $this->logger->error('Failed to encode admin cron JSON response', [
                'error' => $exception->getMessage(),
                'status' => $status,
            ]);

            try {
                $this->errorLogService->logException($exception, $request, [
                    'context_message' => 'Failed to encode admin cron JSON response',
                    'status' => $status,
                ]);
            } catch (\Throwable $loggingError) {
                $this->logger->error('Admin cron JSON encoding error logging failed', [
                    'error' => $loggingError->getMessage(),
                ]);
            }

            $fallbackPayload = [
                'success' => false,
                'message' => 'Failed to encode JSON response',
                'code' => 'JSON_ENCODE_ERROR',
            ];

            if ($status >= 400) {
                $requestId = $request->getAttribute('request_id');
                $fallbackPayload['request_id'] = is_scalar($requestId) || $requestId === null ? $requestId : null;
            }

            try {
                $json = json_encode(
                    $fallbackPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                $json = '{"success":false,"message":"Failed to encode JSON response","code":"JSON_ENCODE_ERROR"}';
            }
        }

        $response->getBody()->write($json);
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
