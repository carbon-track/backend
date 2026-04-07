<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\UserGroupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminUserGroupController
{
    public function __construct(
        private UserGroupService $groupService,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService
    ) {}

    public function list(Request $request, Response $response): Response
    {
        try {
            $groups = $this->groupService->getAllGroups();
            $this->logAudit('admin_user_groups_listed', $request, [
                'data' => ['count' => count($groups)],
            ]);

            return $this->json($response, ['success' => true, 'data' => $groups]);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request, $response, 'Failed to load user groups', 'admin_user_groups_list_failed');
        }
    }

    public function create(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            $group = $this->groupService->createGroup($body);
            $this->logAudit('admin_user_group_created', $request, [
                'record_id' => $group['id'] ?? null,
                'new_data' => $group,
                'data' => ['name' => $group['name'] ?? null],
            ]);

            return $this->json($response, ['success' => true, 'data' => $group]);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request, $response, 'Failed to create user group', 'admin_user_group_create_failed');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            $oldGroup = $this->groupService->getGroupById($id);
            $group = $this->groupService->updateGroup($id, $body);
            $this->logAudit('admin_user_group_updated', $request, [
                'record_id' => $id,
                'old_data' => $oldGroup,
                'new_data' => $group,
                'data' => ['name' => $group['name'] ?? null],
            ]);

            return $this->json($response, ['success' => true, 'data' => $group]);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request, $response, 'Failed to update user group', 'admin_user_group_update_failed');
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];
            $oldGroup = $this->groupService->getGroupById($id);
            $this->groupService->deleteGroup($id);
            $this->logAudit('admin_user_group_deleted', $request, [
                'record_id' => $id,
                'old_data' => $oldGroup,
                'data' => ['name' => $oldGroup['name'] ?? null],
            ]);

            return $this->json($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request, $response, 'Failed to delete user group', 'admin_user_group_delete_failed');
        }
    }

    public function meta(Request $request, Response $response): Response
    {
        try {
            $definitions = $this->groupService->getQuotaDefinitions();
            $this->logAudit('admin_user_group_meta_viewed', $request, [
                'data' => ['count' => count($definitions)],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'quota_definitions' => $definitions,
                    'support_routing_fields' => $this->groupService->getSupportRoutingFieldDefinitions(),
                    'support_routing_defaults' => $this->groupService->getSupportRoutingDefaults(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->handleException($e, $request, $response, 'Failed to load quota definitions', 'admin_user_group_meta_failed');
        }
    }

    private function logAudit(string $action, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $this->auditLogService->logAdminOperation($action, $this->resolveActorId($request), 'admin_user_group', array_merge([
                'table' => 'user_groups',
                'record_id' => $context['record_id'] ?? null,
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

    private function handleException(\Throwable $e, Request $request, Response $response, string $message, string $auditAction): Response
    {
        try {
            $this->errorLogService->logException($e, $request, ['context' => $auditAction]);
        } catch (\Throwable $ignore) {
            // swallow
        }

        $this->logAudit($auditAction, $request, [
            'data' => ['error' => $e->getMessage()],
        ], 'failed');

        return $this->json($response, [
            'success' => false,
            'error' => $message,
        ], 500);
    }

    private function resolveActorId(Request $request): ?int
    {
        $userId = $request->getAttribute('user_id');
        return is_numeric($userId) ? (int)$userId : null;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
