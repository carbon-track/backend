<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use Monolog\Logger;

class BadgeController
{
    public function __construct(
        private AuthService $authService,
        private BadgeService $badgeService,
        private AuditLogService $auditLogService,
        private ?CloudflareR2Service $r2Service = null,
        private ?ErrorLogService $errorLogService = null,
        private ?Logger $logger = null
    ) {}

    public function list(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $query = $request->getQueryParams();
            $includeInactive = false;
            if (!empty($query['include_inactive']) && $this->authService->isAdminUser($user)) {
                $includeInactive = filter_var($query['include_inactive'], FILTER_VALIDATE_BOOLEAN);
            }

            $badges = $this->badgeService->listBadges($includeInactive);
            $data = array_map(function ($badge) {
                return $this->formatBadge($badge->toArray());
            }, $badges);

            return $this->json($response, ['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'badge_list_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to load badges'], 500);
        }
    }

    public function myBadges(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $query = $request->getQueryParams();
            $includeRevoked = !empty($query['include_revoked']) && filter_var($query['include_revoked'], FILTER_VALIDATE_BOOLEAN);
            $records = $this->badgeService->getUserBadges((int) $user['id'], $includeRevoked);

            $data = array_map(function ($entry) {
                $badge = $entry['badge'] ?? [];
                $userBadge = $entry['user_badge'] ?? [];
                return [
                    'badge' => $badge ? $this->formatBadge($badge) : null,
                    'user_badge' => $userBadge,
                ];
            }, $records);

            return $this->json($response, ['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'user_badges_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to load user badges'], 500);
        }
    }

    public function triggerAuto(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $query = $request->getParsedBody();
            if (empty($query)) {
                $query = $request->getQueryParams();
            }
            $badgeId = isset($query['badge_id']) ? (int) $query['badge_id'] : null;
            $targetUserId = isset($query['user_id']) ? (int) $query['user_id'] : null;
            if ($badgeId === 0) {
                $badgeId = null;
            }
            if ($targetUserId === 0) {
                $targetUserId = null;
            }

            $result = $this->badgeService->runAutoGrant($badgeId, $targetUserId);

            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'badge_auto_triggered',
                'entity_type' => 'achievement_badge',
                'new_value' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'notes' => 'Manual trigger via /badges/auto-trigger',
            ]);

            return $this->json($response, ['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'badge_auto_trigger_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to run auto grant'], 500);
        }
    }

    private function formatBadge(array $badge): array
    {
        if ($this->r2Service && !empty($badge['icon_path'])) {
            try {
                $badge['icon_url'] = $this->r2Service->getPublicUrl($badge['icon_path']);
                $badge['icon_presigned_url'] = $this->r2Service->generatePresignedUrl($badge['icon_path'], 600);
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->warning('Failed to build badge icon URLs', ['error' => $e->getMessage(), 'icon_path' => $badge['icon_path']]);
                }
            }
        }
        if ($this->r2Service && !empty($badge['icon_thumbnail_path'])) {
            try {
                $badge['icon_thumbnail_url'] = $this->r2Service->getPublicUrl($badge['icon_thumbnail_path']);
            } catch (\Throwable $ignore) {
            }
        }
        return $badge;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function logError(\Throwable $e, Request $request, string $type): void
    {
        if ($this->logger) {
            $this->logger->error($type, ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        if ($this->errorLogService) {
            try {
                $this->errorLogService->logException($e, $request);
            } catch (\Throwable $ignore) {
                if ($this->logger) {
                    $this->logger->error('ErrorLogService failed', ['error' => $ignore->getMessage()]);
                }
            }
        }
    }
}
