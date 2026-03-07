<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\StatisticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatsController
{
    public function __construct(
        private StatisticsService $statisticsService,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService
    ) {}

    public function getPublicSummary(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $forceParam = $params['force'] ?? $params['refresh'] ?? null;
            $forceRefresh = false;
            if ($forceParam !== null) {
                $parsed = filter_var($forceParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $forceRefresh = $parsed;
                }
            }

            $data = $this->statisticsService->getPublicStats($forceRefresh);

            $this->logSystemAudit('public_stats_summary_viewed', $request, [
                'data' => [
                    'force_refresh' => $forceRefresh,
                    'keys' => array_keys($data),
                ],
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->errorLogService->logException($e, $request, ['context' => 'public_stats_summary_failed']);
            } catch (\Throwable $ignore) {
                // swallow
            }

            $this->logSystemAudit('public_stats_summary_failed', $request, [
                'data' => ['error' => $e->getMessage()],
            ], 'failed');

            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Unable to load statistics',
            ], 500);
        }
    }

    private function logSystemAudit(string $action, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $this->auditLogService->logSystemEvent($action, 'statistics', array_merge([
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => (string)$request->getUri()->getPath(),
                'status' => $status,
                'request_data' => $context['data'] ?? null,
            ], $context));
        } catch (\Throwable $ignore) {
            // 审计日志失败不阻断主流程
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($encoded === false ? '{}' : $encoded);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

