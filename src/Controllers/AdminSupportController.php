<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportAutomationService;
use CarbonTrack\Services\SupportRoutingEngineService;
use CarbonTrack\Services\SupportTicketService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminSupportController
{
    public function __construct(
        private SupportAutomationService $supportAutomationService,
        private SupportTicketService $supportTicketService,
        private SupportRoutingEngineService $supportRoutingEngineService,
        private AuthService $authService,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
        private ErrorLogService $errorLogService
    ) {
    }

    public function listAssignees(Request $request, Response $response): Response
    {
        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->listAssignableUsers()]);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support assignees');
        }
    }

    public function getAssigneeDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $detail = $this->supportAutomationService->getAssignableUserDetail($this->numericId($args, 'id'));
            if ($detail === null) {
                return $this->json($response, ['success' => false, 'message' => 'Support assignee not found', 'code' => 'ASSIGNEE_NOT_FOUND'], 404);
            }
            return $this->json($response, ['success' => true, 'data' => $detail]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support assignee detail');
        }
    }

    public function getRoutingSettings(Request $request, Response $response): Response
    {
        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->getRoutingSettings()]);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support routing settings');
        }
    }

    public function updateRoutingSettings(Request $request, Response $response): Response
    {
        try {
            $actor = $this->currentUser($request);
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->saveRoutingSettings($actor, $this->body($request))]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to save support routing settings');
        }
    }

    public function getAssigneeRoutingProfile(Request $request, Response $response, array $args): Response
    {
        try {
            $profile = $this->supportAutomationService->getAssigneeRoutingProfile($this->numericId($args, 'id'));
            if ($profile === null) {
                return $this->json($response, ['success' => false, 'message' => 'Support assignee not found', 'code' => 'ASSIGNEE_NOT_FOUND'], 404);
            }
            return $this->json($response, ['success' => true, 'data' => $profile]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support assignee routing profile');
        }
    }

    public function updateAssigneeRoutingProfile(Request $request, Response $response, array $args): Response
    {
        try {
            $actor = $this->currentUser($request);
            return $this->json($response, [
                'success' => true,
                'data' => $this->supportAutomationService->saveAssigneeRoutingProfile($actor, $this->numericId($args, 'id'), $this->body($request)),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'ASSIGNEE_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to save support assignee routing profile');
        }
    }

    public function listTags(Request $request, Response $response): Response
    {
        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->listTags()]);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support tags');
        }
    }

    public function createTag(Request $request, Response $response): Response
    {
        return $this->saveTag($request, $response, null, 201);
    }

    public function updateTag(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->saveTag($request, $response, $this->numericId($args, 'id'), 200);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        }
    }

    public function listRules(Request $request, Response $response): Response
    {
        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->listRules()]);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support automation rules');
        }
    }

    public function createRule(Request $request, Response $response): Response
    {
        return $this->saveRule($request, $response, null, 201);
    }

    public function updateRule(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->saveRule($request, $response, $this->numericId($args, 'id'), 200);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        }
    }

    public function reports(Request $request, Response $response): Response
    {
        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->getReports($request->getQueryParams())]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support reports');
        }
    }

    public function listTickets(Request $request, Response $response): Response
    {
        try {
            $actor = $this->currentUser($request);
            $query = $request->getQueryParams();
            $result = $this->supportTicketService->listSupportTickets($actor, $query);
            $this->auditLogService->logAdminOperation('admin_support_tickets_listed', $this->actorId($actor), 'admin_support', [
                'table' => 'support_tickets',
                'request_data' => $query,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
                'new_data' => ['count' => count($result['items'] ?? [])],
            ]);
            return $this->json($response, [
                'success' => true,
                'data' => $result,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->auditLogService->logAdminOperation('admin_support_tickets_list_failed', $this->actorId($this->currentUser($request)), 'admin_support', [
                'table' => 'support_tickets',
                'request_data' => $request->getQueryParams(),
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            $this->auditLogService->logAdminOperation('admin_support_tickets_list_failed', $this->actorId($this->currentUser($request)), 'admin_support', [
                'table' => 'support_tickets',
                'request_data' => $request->getQueryParams(),
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->error($request, $response, $e, 'Failed to load support tickets');
        }
    }

    public function getTicketDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $ticketId = $this->numericId($args, 'id');
            $actor = $this->currentUser($request);
            $detail = $this->supportTicketService->getTicketDetailForSupport($actor, $ticketId);
            $limit = (int) ($_ENV['SUPPORT_ROUTING_AUDIT_LIMIT'] ?? 10);
            $detail['routing_runs'] = $this->supportRoutingEngineService->getRoutingRunsForTicket($ticketId, max(1, $limit));
            $this->auditLogService->logAdminOperation('admin_support_ticket_detail_viewed', $this->actorId($actor), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => $ticketId,
                'request_data' => ['routing_audit_limit' => max(1, $limit)],
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
            ]);
            return $this->json($response, ['success' => true, 'data' => $detail]);
        } catch (\RuntimeException $e) {
            $this->auditLogService->logAdminOperation('admin_support_ticket_detail_failed', $this->actorId($this->currentUser($request)), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : null,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'TICKET_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            $this->auditLogService->logAdminOperation('admin_support_ticket_detail_failed', $this->actorId($this->currentUser($request)), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : null,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            $this->auditLogService->logAdminOperation('admin_support_ticket_detail_failed', $this->actorId($this->currentUser($request)), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : null,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->error($request, $response, $e, 'Failed to load support ticket detail');
        }
    }

    public function updateTicket(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        $ticketId = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : null;
        $requestData = $this->body($request);

        try {
            $ticketId = $this->numericId($args, 'id');
            $detail = $this->supportTicketService->updateTicketFromSupport($actor, $ticketId, $requestData);
            $this->auditLogService->logAdminOperation('admin_support_ticket_updated', $this->actorId($actor), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => $ticketId,
                'request_data' => $requestData,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'success',
            ]);
            return $this->json($response, ['success' => true, 'data' => $detail]);
        } catch (\DomainException $e) {
            $this->logTicketUpdateFailure($request, $actor, $ticketId, $requestData, $e);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'FORBIDDEN'], 403);
        } catch (\RuntimeException $e) {
            $this->logTicketUpdateFailure($request, $actor, $ticketId, $requestData, $e);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'TICKET_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            $this->logTicketUpdateFailure($request, $actor, $ticketId, $requestData, $e);
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            $this->auditLogService->logAdminOperation('admin_support_ticket_update_failed', $this->actorId($actor), 'admin_support', [
                'table' => 'support_tickets',
                'record_id' => $ticketId,
                'request_data' => $requestData,
                'request_id' => $request->getAttribute('request_id'),
                'status' => 'failed',
                'data' => ['error' => $e->getMessage()],
            ]);
            return $this->error($request, $response, $e, 'Failed to update support ticket');
        }
    }

    private function logTicketUpdateFailure(Request $request, array $actor, ?int $ticketId, array $requestData, \Throwable $exception): void
    {
        $this->auditLogService->logAdminOperation('admin_support_ticket_update_failed', $this->actorId($actor), 'admin_support', [
            'table' => 'support_tickets',
            'record_id' => $ticketId,
            'request_data' => $requestData,
            'request_id' => $request->getAttribute('request_id'),
            'status' => 'failed',
            'data' => ['error' => $exception->getMessage()],
        ]);

        try {
            $this->errorLogService->logException($exception, $request, [
                'context_message' => 'Admin support ticket update failed',
                'ticket_id' => $ticketId,
                'request_data' => $requestData,
            ]);
        } catch (\Throwable $loggingError) {
            $this->logger->error('Admin support ticket update failure logging failed', [
                'error' => $loggingError->getMessage(),
                'ticket_id' => $ticketId,
            ]);
        }
    }

    private function saveTag(Request $request, Response $response, ?int $tagId, int $status): Response
    {
        try {
            $actor = $this->currentUser($request);
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->saveTag($actor, $this->body($request), $tagId)], $status);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'TAG_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to save support tag');
        }
    }

    private function saveRule(Request $request, Response $response, ?int $ruleId, int $status): Response
    {
        try {
            $actor = $this->currentUser($request);
            return $this->json($response, ['success' => true, 'data' => $this->supportAutomationService->saveRule($actor, $this->body($request), $ruleId)], $status);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'RULE_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to save support automation rule');
        }
    }

    private function currentUser(Request $request): array
    {
        $user = $this->authService->getCurrentUser($request);
        return is_array($user) ? $user : [];
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function numericId(array $args, string $key): int
    {
        $value = isset($args[$key]) ? (int) $args[$key] : 0;
        if ($value <= 0) {
            throw new \InvalidArgumentException('Invalid id');
        }
        return $value;
    }

    private function error(Request $request, Response $response, \Throwable $e, string $message): Response
    {
        $this->logger->error($message, ['error' => $e->getMessage()]);
        try {
            $this->errorLogService->logException($e, $request, ['context_message' => $message]);
        } catch (\Throwable $loggingError) {
            $this->logger->error('Admin support logging failed', ['error' => $loggingError->getMessage()]);
        }
        return $this->json($response, ['success' => false, 'message' => $message, 'code' => 'INTERNAL_ERROR'], 500);
    }

    private function actorId(array $actor): ?int
    {
        return isset($actor['id']) && is_numeric($actor['id']) ? (int) $actor['id'] : null;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
