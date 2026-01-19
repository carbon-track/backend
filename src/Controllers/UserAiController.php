<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\UserAiService;
use CarbonTrack\Services\CarbonCalculatorService;
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
        private \CarbonTrack\Services\AuthService $authService
    ) {}

    public function suggestActivity(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $query = isset($body['query']) ? trim((string)$body['query']) : '';
        
        if ($query === '') {
            return $this->json($response, ['success' => false, 'error' => 'Query is required'], 400);
        }

        if (mb_strlen($query) > 500) {
             return $this->json($response, ['success' => false, 'error' => 'Query too long'], 400);
        }

        $clientMeta = [];
        if (!empty($body['client_time'])) {
            $clientMeta['client_time'] = (string) $body['client_time'];
        }
        if (!empty($body['client_timezone'])) {
            $clientMeta['client_timezone'] = (string) $body['client_timezone'];
        }

        // Quota Check
        $userModel = $this->authService->getCurrentUserModel($request);
        if ($userModel) {
            // 'llm' is the resource key
            if (!$this->quotaService->checkAndConsume($userModel, 'llm', 1)) {
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
            $result = $this->aiService->suggestActivity($query, $activityContext, $clientMeta);
            return $this->json($response, $result);
        } catch (\Throwable $e) {
            $this->logger->error('AI Suggest Error: ' . $e->getMessage());
            
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
}
