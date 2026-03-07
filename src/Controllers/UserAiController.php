<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\UserAiService;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\QuotaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class UserAiController
{
    public function __construct(
        private UserAiService $aiService,
        private CarbonCalculatorService $calculatorService,
        private QuotaService $quotaService,
        private LoggerInterface $logger,
        private \CarbonTrack\Services\AuthService $authService,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService
    ) {}

    public function suggestActivity(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }
        $query = isset($body['query']) ? trim((string)$body['query']) : '';
        $userModel = $this->authService->getCurrentUserModel($request);
        $userId = $this->resolveUserId($request, $userModel);
        
        if ($query === '') {
            $this->logUserAudit('user_ai_suggest_validation_failed', $request, $userId, [
                'data' => ['reason' => 'missing_query'],
            ], 'failed');
            return $this->json($response, ['success' => false, 'error' => 'Query is required'], 400);
        }

        if (mb_strlen($query) > 500) {
            $this->logUserAudit('user_ai_suggest_validation_failed', $request, $userId, [
                'data' => ['reason' => 'query_too_long', 'query_length' => mb_strlen($query)],
            ], 'failed');
             return $this->json($response, ['success' => false, 'error' => 'Query too long'], 400);
        }

        $clientMeta = [];
        if (!empty($body['client_time'])) {
            $clientMeta['client_time'] = (string) $body['client_time'];
        }
        if (!empty($body['client_timezone'])) {
            $clientMeta['client_timezone'] = (string) $body['client_timezone'];
        }

        $source = null;
        if (!empty($body['entry'])) {
            $source = trim((string) $body['entry']);
        } elseif (!empty($body['source'])) {
            $source = trim((string) $body['source']);
        } elseif (!empty($body['entry_point'])) {
            $source = trim((string) $body['entry_point']);
        }
        if ($source === '') {
            $source = null;
        }

        // Quota Check
        if ($userModel) {
            // 'llm' is the resource key
            if (!$this->quotaService->checkAndConsume($userModel, 'llm', 1)) {
                $this->logUserAudit('user_ai_suggest_quota_rejected', $request, $userId, [
                    'data' => [
                        'source' => $source ?? $request->getUri()->getPath(),
                        'query_length' => mb_strlen($query),
                    ],
                ], 'failed');
                // Return i18n friendly error
                return $this->json($response, [
                    'success' => false, 
                    'error' => 'Daily limit or rate limit exceeded',
                    'code' => 'QUOTA_EXCEEDED', // Frontend can map this to error.quota.exceeded
                    'translation_key' => 'error.quota.exceeded'
                ], 429);
            }
        }

        // Get activities for context
        $activities = $this->calculatorService->getAvailableActivities(null, null, false);
        $activityContext = [];
        foreach ($activities as $activity) {
            // Keep UUID/id for precise matching
            $name = $activity['name_en'] ?? $activity['name_zh'] ?? ($activity['combined_name'] ?? null);
            if (isset($activity['name_en'], $activity['name_zh'])) {
                $name = "{$activity['name_en']} / {$activity['name_zh']}";
            }
            $cat = $activity['category'] ?? 'General';
            $activityContext[] = [
                'id' => (string)($activity['id'] ?? ''),
                'label' => $name ?? $activity['id'] ?? 'Unknown',
                'category' => $cat,
                'unit' => $activity['unit'] ?? null,
            ];
        }

        try {
            $logContext = [
                'request_id' => $request->getAttribute('request_id'),
                'actor_type' => 'user',
                'actor_id' => $userId,
                'source' => $source ?? $request->getUri()->getPath(),
            ];
            $result = $this->aiService->suggestActivity($query, $activityContext, $clientMeta, $logContext);

            $this->logUserAudit('user_ai_activity_suggested', $request, $userId, [
                'data' => [
                    'source' => $source ?? $request->getUri()->getPath(),
                    'query_length' => mb_strlen($query),
                    'prediction' => $result['prediction']['activity_name'] ?? null,
                ],
            ]);

            return $this->json($response, $result);
        } catch (\Throwable $e) {
            $this->logger->error('AI Suggest Error: ' . $e->getMessage());
            try {
                $this->errorLogService->logException($e, $request, ['context' => 'user_ai_suggest_failed']);
            } catch (\Throwable $ignore) {
                // swallow
            }

            $this->logUserAudit('user_ai_suggest_failed', $request, $userId, [
                'data' => [
                    'source' => $source ?? $request->getUri()->getPath(),
                    'query_length' => mb_strlen($query),
                    'error' => $e->getMessage(),
                ],
            ], 'failed');
            
            // Helpful error if disabled
            if ($e->getMessage() === 'AI service is disabled') {
                return $this->json($response, [
                    'success' => false, 
                    'error' => 'AI assistant is not configured on this server.'
                ], 503);
            }

            return $this->json($response, [
                'success' => false, 
                'error' => 'AI Service temporarily unavailable.'
            ], 503);
        }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function logUserAudit(string $action, Request $request, ?int $userId, array $context = [], string $status = 'success'): void
    {
        try {
            $this->auditLogService->logUserAction($userId, $action, array_merge([
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

    private function resolveUserId(Request $request, mixed $userModel): ?int
    {
        $userId = $request->getAttribute('user_id');
        if (is_numeric($userId)) {
            return (int)$userId;
        }

        if (is_object($userModel) && isset($userModel->id) && is_numeric((string)$userModel->id)) {
            return (int)$userModel->id;
        }

        return null;
    }
}
