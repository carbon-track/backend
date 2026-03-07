<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LeaderboardService;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LeaderboardController
{
    public function __construct(
        private LeaderboardService $leaderboardService,
        private Logger $logger,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService
    ) {}

    public function triggerRefresh(Request $request, Response $response): Response
    {
        try {
            $query = $request->getQueryParams();
            $providedKey = (string) ($query['key'] ?? $query['trigger_key'] ?? '');
            $expectedKey = trim((string) ($_ENV['LEADERBOARD_TRIGGER_KEY'] ?? ''));

            if ($expectedKey === '') {
                $this->logSystemAudit('leaderboard_refresh_unconfigured', $request, [
                    'data' => ['reason' => 'missing_trigger_key_config'],
                ], 'failed');

                return $this->json($response, [
                    'success' => false,
                    'message' => 'Trigger key is not configured on the server',
                ], 503);
            }

            if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
                $this->logSystemAudit('leaderboard_refresh_rejected', $request, [
                    'data' => ['reason' => 'invalid_trigger_key'],
                ], 'failed');

                return $this->json($response, [
                    'success' => false,
                    'message' => 'Invalid trigger key',
                ], 403);
            }

            $snapshot = $this->leaderboardService->rebuildCache('manual-trigger');
            $meta = [
                'generated_at' => $snapshot['generated_at'] ?? null,
                'expires_at' => $snapshot['expires_at'] ?? null,
                'global_count' => isset($snapshot['global']) ? count($snapshot['global']) : 0,
                'regions_count' => isset($snapshot['regions']) ? count($snapshot['regions']) : 0,
                'schools_count' => isset($snapshot['schools']) ? count($snapshot['schools']) : 0,
            ];

            $this->logger->info('Leaderboard cache refreshed via trigger', $meta);
            $this->logSystemAudit('leaderboard_cache_refreshed', $request, [
                'data' => $meta,
            ]);

            return $this->json($response, [
                'success' => true,
                'message' => 'Leaderboard cache refreshed',
                'data' => $meta,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->errorLogService->logException($e, $request, ['context' => 'leaderboard_refresh_failed']);
            } catch (\Throwable $ignore) {
                // swallow
            }

            $this->logSystemAudit('leaderboard_refresh_failed', $request, [
                'data' => ['error' => $e->getMessage()],
            ], 'failed');

            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to refresh leaderboard cache',
            ], 500);
        }
    }

    private function logSystemAudit(string $action, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $this->auditLogService->logSystemEvent($action, 'leaderboard', array_merge([
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => (string)$request->getUri()->getPath(),
                'status' => $status,
                'request_data' => $context['data'] ?? null,
                'old_data' => $context['old_data'] ?? null,
                'new_data' => $context['new_data'] ?? null,
            ], $context));
        } catch (\Throwable $ignore) {
            // 审计日志失败不阻断主流程
        }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload === false ? '{}' : $payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
