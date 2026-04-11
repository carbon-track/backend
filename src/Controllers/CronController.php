<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CronSchedulerService;
use CarbonTrack\Services\ErrorLogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class CronController
{
    public function __construct(
        private CronSchedulerService $cronSchedulerService,
        private LoggerInterface $logger,
        private ErrorLogService $errorLogService,
        private AuditLogService $auditLogService
    ) {
    }

    public function run(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $providedKey = is_string($query['key'] ?? null)
            ? trim((string) $query['key'])
            : trim((string) ($_GET['key'] ?? ''));
        $configuredKey = trim((string) ($_ENV['CRON_RUN_KEY'] ?? getenv('CRON_RUN_KEY') ?: ''));

        if ($configuredKey === '') {
            $this->auditSafely('cron_run_endpoint_unconfigured', [
                'status' => 'failed',
                'request_method' => 'GET',
                'endpoint' => (string) $request->getUri()->getPath(),
                'request_data' => ['remote_addr' => $this->clientIp($request)],
                'request_id' => $request->getAttribute('request_id'),
            ], $request);

            return $this->json($request, $response, [
                'success' => false,
                'message' => 'Cron key is not configured',
                'code' => 'CRON_UNAVAILABLE',
            ], 503);
        }

        if ($providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
            $this->auditSafely('cron_run_endpoint_denied', [
                'status' => 'failed',
                'request_method' => 'GET',
                'endpoint' => (string) $request->getUri()->getPath(),
                'request_data' => ['remote_addr' => $this->clientIp($request)],
                'request_id' => $request->getAttribute('request_id'),
            ], $request);

            return $this->json($request, $response, [
                'success' => false,
                'message' => 'Invalid cron key',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        try {
            $result = $this->cronSchedulerService->runDueTasks('cron_endpoint', [
                'request_id' => $request->getAttribute('request_id'),
                'remote_addr' => $this->clientIp($request),
            ]);

            $this->auditSafely('cron_run_endpoint_triggered', [
                'status' => !empty($result['failed']) || !empty($result['skipped']) ? 'failed' : 'success',
                'request_method' => 'GET',
                'endpoint' => (string) $request->getUri()->getPath(),
                'request_id' => $request->getAttribute('request_id'),
                'request_data' => [
                    'remote_addr' => $this->clientIp($request),
                    'due_count' => count($result['due'] ?? []),
                    'executed_count' => count($result['executed'] ?? []),
                    'failed_count' => count($result['failed'] ?? []),
                    'skipped_count' => count($result['skipped'] ?? []),
                ],
            ], $request);

            $failedCount = count($result['failed'] ?? []);
            $skippedCount = count($result['skipped'] ?? []);
            $executedCount = count($result['executed'] ?? []);

            if ($failedCount > 0) {
                return $this->json($request, $response, [
                    'success' => false,
                    'message' => 'One or more cron tasks failed',
                    'code' => 'CRON_RUN_FAILED',
                    'data' => $result,
                ], 503);
            }

            if ($skippedCount > 0) {
                return $this->json($request, $response, [
                    'success' => false,
                    'message' => $executedCount > 0
                        ? 'One or more cron tasks were skipped'
                        : 'All due cron tasks were skipped',
                    'code' => 'CRON_RUN_SKIPPED',
                    'data' => $result,
                ], 409);
            }

            return $this->json($request, $response, ['success' => true, 'data' => $result]);
        } catch (\Throwable $exception) {
            return $this->error($request, $response, $exception, 'Failed to run scheduled cron tasks');
        }
    }

    private function clientIp(Request $request): ?string
    {
        $serverParams = $request->getServerParams();
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $value = $serverParams[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            return str_contains($value, ',') ? trim(explode(',', $value)[0]) : trim($value);
        }

        return null;
    }

    private function auditSafely(string $action, array $payload, Request $request): void
    {
        try {
            $this->auditLogService->logSystemEvent($action, 'cron_scheduler', $payload);
        } catch (\Throwable $exception) {
            $this->logger->warning('Cron endpoint audit logging failed', [
                'action' => $action,
                'path' => (string) $request->getUri()->getPath(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function error(Request $request, Response $response, \Throwable $exception, string $message): Response
    {
        $this->logger->error($message, ['error' => $exception->getMessage()]);
        try {
            $this->errorLogService->logException($exception, $request, ['context_message' => $message]);
        } catch (\Throwable $loggingError) {
            $this->logger->error('Cron endpoint error logging failed', ['error' => $loggingError->getMessage()]);
        }

        return $this->json($request, $response, [
            'success' => false,
            'message' => $message,
            'code' => 'INTERNAL_ERROR',
        ], 500);
    }

    private function json(Request $request, Response $response, array $payload, int $status = 200): Response
    {
        if ($status >= 400 && !array_key_exists('request_id', $payload)) {
            $payload['request_id'] = $request->getAttribute('request_id');
        }
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
