<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\AuditLogService;
use Monolog\Logger;

class AdminBadgeController
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
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $query = $request->getQueryParams();
            $includeInactive = !empty($query['include_inactive']) && filter_var($query['include_inactive'], FILTER_VALIDATE_BOOLEAN);
            $badges = $this->badgeService->listBadges($includeInactive);
            $data = array_map(function ($badge) {
                return $this->formatBadge($badge->toArray());
            }, $badges);

            return $this->json($response, ['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_list_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to load badges'], 500);
        }
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $badge = $this->badgeService->findBadge((int) ($args['id'] ?? 0));
            if (!$badge) {
                return $this->json($response, ['success' => false, 'message' => 'Badge not found'], 404);
            }

            return $this->json($response, ['success' => true, 'data' => $this->formatBadge($badge->toArray())]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_detail_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to load badge'], 500);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $data = $request->getParsedBody() ?: [];
            $badge = $this->badgeService->createBadge($data, (int) $admin['id']);
            return $this->json($response, ['success' => true, 'data' => $this->formatBadge($badge->toArray())], 201);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_create_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to create badge'], 500);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $data = $request->getParsedBody() ?: [];
            $badge = $this->badgeService->updateBadge((int) ($args['id'] ?? 0), $data, (int) $admin['id']);
            if (!$badge) {
                return $this->json($response, ['success' => false, 'message' => 'Badge not found'], 404);
            }

            return $this->json($response, ['success' => true, 'data' => $this->formatBadge($badge->toArray())]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_update_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to update badge'], 500);
        }
    }

    public function award(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $data = $request->getParsedBody() ?: [];
            $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
            if ($userId <= 0) {
                return $this->json($response, ['success' => false, 'message' => 'user_id is required'], 400);
            }

            $userBadge = $this->badgeService->awardBadge((int) ($args['id'] ?? 0), $userId, [
                'source' => 'manual',
                'admin_id' => (int) $admin['id'],
                'notes' => $data['notes'] ?? null,
            ]);
            if (!$userBadge) {
                return $this->json($response, ['success' => false, 'message' => 'Badge or user not found'], 404);
            }

            return $this->json($response, ['success' => true, 'data' => $userBadge->toArray()]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_award_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to award badge'], 500);
        }
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $data = $request->getParsedBody() ?: [];
            $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
            if ($userId <= 0) {
                return $this->json($response, ['success' => false, 'message' => 'user_id is required'], 400);
            }

            $ok = $this->badgeService->revokeBadge((int) ($args['id'] ?? 0), $userId, (int) $admin['id'], $data['notes'] ?? null);
            if (!$ok) {
                return $this->json($response, ['success' => false, 'message' => 'Badge record not found or already revoked'], 404);
            }

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_revoke_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to revoke badge'], 500);
        }
    }

    public function triggerAuto(Request $request, Response $response): Response
    {
        try {
            $admin = $this->requireAdmin($request);
            if (!$admin) {
                return $this->json($response, ['success' => false, 'message' => 'Access denied'], 403);
            }

            $query = $request->getQueryParams();
            $badgeId = isset($query['badge_id']) ? (int) $query['badge_id'] : null;
            $userId = isset($query['user_id']) ? (int) $query['user_id'] : null;
            if ($badgeId === 0) {
                $badgeId = null;
            }
            if ($userId === 0) {
                $userId = null;
            }

            $result = $this->badgeService->runAutoGrant($badgeId, $userId);

            $this->auditLogService->log([
                'user_id' => $admin['id'],
                'action' => 'badge_auto_triggered',
                'entity_type' => 'achievement_badge',
                'new_value' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'notes' => 'Manual trigger by admin',
            ]);

            return $this->json($response, ['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            $this->logError($e, $request, 'admin_badge_auto_failed');
            return $this->json($response, ['success' => false, 'message' => 'Failed to run auto grant'], 500);
        }
    }

    private function requireAdmin(Request $request): ?array
    {
        $user = $this->authService->getCurrentUser($request);
        if (!$user || !$this->authService->isAdminUser($user)) {
            return null;
        }
        return $user;
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
            } catch (\Throwable $ignore) {}
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
