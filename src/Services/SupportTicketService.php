<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\SupportTicket;
use CarbonTrack\Models\SupportTicketAttachment;
use CarbonTrack\Models\SupportTicketFeedback;
use CarbonTrack\Models\SupportTicketMessage;
use CarbonTrack\Models\SupportTicketTransferRequest;
use PDO;
use Psr\Log\LoggerInterface;

class SupportTicketService
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_USER = 'waiting_user';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const TRANSFER_STATUS_PENDING = 'pending';
    public const TRANSFER_STATUS_APPROVED = 'approved';
    public const TRANSFER_STATUS_REJECTED = 'rejected';
    public const TRANSFER_STATUS_CANCELLED = 'cancelled';

    private const VALID_CATEGORIES = ['website_bug', 'business_issue', 'feature_request', 'account', 'other'];
    private const VALID_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_USER,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];
    private const VALID_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    private const VALID_TRANSFER_STATUSES = [
        self::TRANSFER_STATUS_PENDING,
        self::TRANSFER_STATUS_APPROVED,
        self::TRANSFER_STATUS_REJECTED,
        self::TRANSFER_STATUS_CANCELLED,
    ];
    private const FEEDBACK_ALLOWED_STATUSES = [
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService,
        private FileMetadataService $fileMetadataService,
        private ?EmailService $emailService = null,
        private ?MessageService $messageService = null,
        private ?CloudflareR2Service $r2Service = null,
        private ?SupportAutomationService $supportAutomationService = null,
        private ?SupportRoutingEngineService $supportRoutingEngineService = null
    ) {
    }

    public function createTicket(array $actor, array $payload): array
    {
        $subject = $this->requireString($payload['subject'] ?? null, 'subject');
        $body = $this->requireString($payload['content'] ?? null, 'content');
        $category = $this->normalizeCategory($payload['category'] ?? null);
        $priority = $this->normalizePriority($payload['priority'] ?? null);
        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);
        $now = $this->now();

        try {
            $this->db->beginTransaction();

            $ticket = SupportTicket::create([
                'user_id' => (int) $actor['id'],
                'subject' => $subject,
                'category' => $category,
                'status' => self::STATUS_OPEN,
                'priority' => $priority,
                'last_replied_at' => $now,
                'last_reply_by_role' => 'user',
            ]);

            $message = SupportTicketMessage::create([
                'ticket_id' => (int) $ticket->id,
                'sender_id' => (int) $actor['id'],
                'sender_role' => 'user',
                'sender_name' => $this->actorName($actor),
                'body' => $body,
            ]);

            $this->attachFiles((int) $ticket->id, (int) $message->id, $attachments, (int) $actor['id'], false);
            $this->db->commit();

            $this->auditLogService->log([
                'user_id' => (int) $actor['id'],
                'action' => 'support_ticket_created',
                'operation_category' => 'support',
                'actor_type' => 'user',
                'affected_table' => 'support_tickets',
                'affected_id' => (int) $ticket->id,
                'status' => 'success',
                'new_data' => ['category' => $category, 'priority' => $priority, 'attachment_count' => count($attachments)],
            ]);

            if ($this->supportRoutingEngineService !== null) {
                try {
                    $this->supportRoutingEngineService->routeTicket((int) $ticket->id, 'created');
                } catch (\Throwable $routingException) {
                    $this->logger->warning('Support ticket routing failed after ticket creation', [
                        'ticket_id' => (int) $ticket->id,
                        'error' => $routingException->getMessage(),
                    ]);
                    $this->recordFailure($routingException, 'support_ticket_routing_failed', $actor, (int) $ticket->id);
                }
            }

            $detail = $this->getTicketDetailForUser((int) $actor['id'], (int) $ticket->id);
            $this->notifySupportMailbox(
                sprintf('New support ticket #%d: %s', (int) $ticket->id, $subject),
                $this->supportMailboxBody($actor, $detail, $body)
            );
            return $detail;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordFailure($e, 'support_ticket_create_failed', $actor, null);
            throw $e;
        }
    }

    public function listUserTickets(int $userId, array $query = []): array
    {
        $result = $this->listTickets(false, ['user_id' => $userId], $query);
        $this->auditLogService->log([
            'user_id' => $userId,
            'action' => 'support_ticket_list_viewed',
            'operation_category' => 'support',
            'actor_type' => 'user',
            'affected_table' => 'support_tickets',
            'status' => 'success',
            'data' => $result['pagination'],
        ]);
        return $result;
    }

    public function getTicketDetailForUser(int $userId, int $ticketId): array
    {
        $ticket = $this->findTicketForUser($userId, $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }
        $detail = $this->formatTicketDetail($ticket, false);
        $this->auditLogService->log([
            'user_id' => $userId,
            'action' => 'support_ticket_detail_viewed',
            'operation_category' => 'support',
            'actor_type' => 'user',
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => 'success',
        ]);
        return $detail;
    }

    public function addUserMessage(array $actor, int $ticketId, array $payload): array
    {
        $ticket = $this->findTicketForUser((int) $actor['id'], $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }
        if (($ticket['status'] ?? '') === self::STATUS_CLOSED) {
            throw new \RuntimeException('Closed tickets cannot receive new replies');
        }

        $body = $this->requireString($payload['content'] ?? null, 'content');
        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);
        $now = $this->now();

        try {
            $this->db->beginTransaction();
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticketId,
                'sender_id' => (int) $actor['id'],
                'sender_role' => 'user',
                'sender_name' => $this->actorName($actor),
                'body' => $body,
            ]);
            $this->attachFiles($ticketId, (int) $message->id, $attachments, (int) $actor['id'], false);
            $nextStatus = in_array((string) $ticket['status'], [self::STATUS_WAITING_USER, self::STATUS_RESOLVED], true)
                ? self::STATUS_OPEN
                : (string) $ticket['status'];
            $this->updateTicket($ticketId, [
                'status' => $nextStatus,
                'last_replied_at' => $now,
                'last_reply_by_role' => 'user',
                'updated_at' => $now,
            ]);
            $this->db->commit();

            $this->auditLogService->log([
                'user_id' => (int) $actor['id'],
                'action' => 'support_ticket_reply_created',
                'operation_category' => 'support',
                'actor_type' => 'user',
                'affected_table' => 'support_ticket_messages',
                'affected_id' => (int) $message->id,
                'status' => 'success',
                'data' => ['ticket_id' => $ticketId, 'attachment_count' => count($attachments)],
            ]);

            $detail = $this->getTicketDetailForUser((int) $actor['id'], $ticketId);
            $this->notifySupportMailbox(
                sprintf('User replied on support ticket #%d: %s', $ticketId, $ticket['subject'] ?? ''),
                $this->supportMailboxBody($actor, $detail, $body)
            );
            return $detail;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordFailure($e, 'support_ticket_reply_create_failed', $actor, $ticketId);
            throw $e;
        }
    }

    public function submitTicketFeedback(array $actor, int $ticketId, array $payload): array
    {
        $ticket = $this->findTicketForUser((int) $actor['id'], $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }
        if (!in_array((string) ($ticket['status'] ?? ''), self::FEEDBACK_ALLOWED_STATUSES, true)) {
            throw new \RuntimeException('Feedback is only available after the ticket is resolved or closed');
        }

        $ratedUserId = (int) ($payload['rated_user_id'] ?? 0);
        if ($ratedUserId <= 0) {
            throw new \InvalidArgumentException('rated_user_id is required');
        }

        $candidate = $this->findFeedbackCandidate($ticketId, $ratedUserId);
        if ($candidate === null) {
            throw new \InvalidArgumentException('Rated user is not eligible for feedback on this ticket');
        }

        $rating = $this->normalizeRating($payload['rating'] ?? null);
        $comment = $this->normalizeFeedbackComment($payload['comment'] ?? null);
        $feedback = $this->findFeedbackRecord($ticketId, (int) $actor['id'], $ratedUserId);
        $isNew = $feedback === null;
        $now = $this->now();

        if ($feedback === null) {
            $feedback = SupportTicketFeedback::create([
                'ticket_id' => $ticketId,
                'user_id' => (int) $actor['id'],
                'rated_user_id' => $ratedUserId,
                'rating' => $rating,
                'comment' => $comment,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $feedback->fill([
                'rating' => $rating,
                'comment' => $comment,
                'updated_at' => $now,
            ]);
            $feedback->save();
        }

        $this->auditLogService->log([
            'user_id' => (int) $actor['id'],
            'action' => $isNew ? 'support_ticket_feedback_created' : 'support_ticket_feedback_updated',
            'operation_category' => 'support',
            'actor_type' => 'user',
            'affected_table' => 'support_ticket_feedback',
            'affected_id' => (int) ($feedback->id ?? 0),
            'status' => 'success',
            'data' => [
                'ticket_id' => $ticketId,
                'rated_user_id' => $ratedUserId,
                'rating' => $rating,
            ],
        ]);

        return $this->getTicketDetailForUser((int) $actor['id'], $ticketId);
    }

    public function listSupportTickets(array $actor, array $query = []): array
    {
        $pendingTransferTargetView = $this->isPendingTransferTargetQuery($actor, $query);
        $result = $this->listTickets(true, $this->supportTicketBaseFilters($actor, $query), $this->supportTicketQuery($actor, $query));

        if ($pendingTransferTargetView && !empty($result['items'])) {
            $pendingTransferMap = $this->pendingTransferRequestsForTarget(
                array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $result['items']),
                (int) ($actor['id'] ?? 0)
            );
            $result['items'] = array_map(static function (array $item) use ($pendingTransferMap): array {
                $item['pending_transfer_request'] = $pendingTransferMap[(int) ($item['id'] ?? 0)] ?? null;
                return $item;
            }, $result['items']);
        }

        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => 'support_ticket_queue_viewed',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_tickets',
            'status' => 'success',
            'data' => $result['pagination'],
        ]);
        return $result;
    }

    public function listSupportAssignees(array $actor): array
    {
        $items = $this->supportAutomationService?->listAssignableUsers() ?? [];
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => 'support_assignee_list_viewed',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'users',
            'status' => 'success',
            'data' => ['count' => count($items)],
        ]);
        return $items;
    }

    public function getTicketDetailForSupport(array $actor, int $ticketId): array
    {
        $ticket = $this->findTicketForSupport($actor, $ticketId, true);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }
        $detail = $this->formatTicketDetail($ticket, true);
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => 'support_ticket_detail_viewed',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => 'success',
        ]);
        return $detail;
    }

    public function addSupportMessage(array $actor, int $ticketId, array $payload): array
    {
        $ticket = $this->findTicketForSupport($actor, $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        $body = $this->requireString($payload['content'] ?? null, 'content');
        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);
        $senderRole = !empty($actor['is_admin']) ? 'admin' : 'support';
        $now = $this->now();

        try {
            $this->db->beginTransaction();
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticketId,
                'sender_id' => (int) $actor['id'],
                'sender_role' => $senderRole,
                'sender_name' => $this->actorName($actor),
                'body' => $body,
            ]);
            $this->attachFiles($ticketId, (int) $message->id, $attachments, (int) $actor['id'], true);
            $updates = [
                'status' => self::STATUS_WAITING_USER,
                'last_replied_at' => $now,
                'last_reply_by_role' => $senderRole,
                'updated_at' => $now,
            ];
            if (empty($ticket['first_support_response_at'])) {
                $updates['first_support_response_at'] = $now;
            }
            if (($ticket['sla_status'] ?? 'pending') === 'resolved') {
                $updates['sla_status'] = 'pending';
            }
            $this->updateTicket($ticketId, $updates);
            $this->db->commit();

            $this->auditLogService->log([
                'user_id' => (int) ($actor['id'] ?? 0),
                'action' => 'support_ticket_support_reply_created',
                'operation_category' => 'support',
                'actor_type' => $this->actorType($actor),
                'affected_table' => 'support_ticket_messages',
                'affected_id' => (int) $message->id,
                'status' => 'success',
                'data' => ['ticket_id' => $ticketId, 'attachment_count' => count($attachments)],
            ]);

            $this->notifyUserReply($ticket, $body, $ticketId);
            return $this->getTicketDetailForSupport($actor, $ticketId);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->recordFailure($e, 'support_ticket_support_reply_failed', $actor, $ticketId);
            throw $e;
        }
    }

    public function updateTicketFromSupport(array $actor, int $ticketId, array $payload): array
    {
        $ticket = $this->findTicketForSupport($actor, $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        $updates = [];
        $now = $this->now();
        $assigneeToNotify = null;
        if (array_key_exists('status', $payload) && $payload['status'] !== null && $payload['status'] !== '') {
            $status = $this->normalizeStatus($payload['status']);
            $updates['status'] = $status;
            if ($status === self::STATUS_RESOLVED) {
                $updates['resolved_at'] = $now;
                $updates['sla_status'] = 'resolved';
            }
            if ($status === self::STATUS_CLOSED) {
                $updates['closed_at'] = $now;
                $updates['sla_status'] = 'resolved';
            }
            if (in_array($status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_USER], true) && ($ticket['sla_status'] ?? null) === 'resolved') {
                $updates['sla_status'] = 'pending';
            }
        }
        if (array_key_exists('priority', $payload) && $payload['priority'] !== null && $payload['priority'] !== '') {
            $updates['priority'] = $this->normalizePriority($payload['priority']);
        }
        if (array_key_exists('assigned_to', $payload)) {
            if (empty($actor['is_admin'])) {
                throw new \DomainException('Only administrators can manually assign or transfer tickets');
            }
            $assigned = $payload['assigned_to'];
            if ($assigned === null || $assigned === '' || (int) $assigned <= 0) {
                $updates['assigned_to'] = null;
                $updates['assignment_source'] = null;
                $updates['assigned_rule_id'] = null;
                $updates['assignment_locked'] = 0;
            } else {
                $assignee = $this->findAssignableUser((int) $assigned);
                if ($assignee === null) {
                    throw new \InvalidArgumentException('Assigned user must be support or admin');
                }
                $updates['assigned_to'] = (int) $assignee['id'];
                $updates['assignment_source'] = 'manual';
                $updates['assigned_rule_id'] = null;
                $updates['assignment_locked'] = 1;
                $assigneeToNotify = $this->loadUserById((int) $assignee['id']);
            }
        }
        if ($updates === []) {
            return $this->getTicketDetailForSupport($actor, $ticketId);
        }
        $updates['updated_at'] = $now;
        $this->updateTicket($ticketId, $updates);
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => 'support_ticket_updated',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => 'success',
            'old_data' => $ticket,
            'new_data' => $updates,
        ]);
        $this->notifyUserTicketUpdated($ticket, $updates, $ticketId);
        if ($assigneeToNotify !== null) {
            $this->notifyAssignee(
                $assigneeToNotify,
                sprintf('Ticket #%d assigned to you', $ticketId),
                sprintf("An administrator assigned ticket #%d to you.\nSubject: %s", $ticketId, (string) ($ticket['subject'] ?? '')),
                $ticketId,
                'support_ticket_manual_assignment_notified'
            );
        }
        return $this->getTicketDetailForSupport($actor, $ticketId);
    }

    public function createTransferRequest(array $actor, int $ticketId, array $payload): array
    {
        if ($this->isAdminActor($actor)) {
            throw new \DomainException('Administrators can manually transfer tickets without creating a request');
        }

        $ticket = $this->findTicketForSupport($actor, $ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        $actorId = (int) ($actor['id'] ?? 0);
        if ($actorId <= 0 || (int) ($ticket['assigned_to'] ?? 0) !== $actorId) {
            throw new \DomainException('Only the current assignee can request a transfer');
        }

        $existingPending = $this->findPendingTransferRequestForTicket($ticketId);
        if ($existingPending !== null) {
            throw new \InvalidArgumentException('A pending transfer request already exists for this ticket');
        }

        $targetId = (int) ($payload['to_assignee'] ?? 0);
        $assignee = $this->findAssignableUser($targetId);
        if ($assignee === null || (int) ($assignee['id'] ?? 0) === $actorId) {
            throw new \InvalidArgumentException('Transfer target must be another support or admin user');
        }

        $reason = $this->nullableString($payload['reason'] ?? null);
        $request = SupportTicketTransferRequest::create([
            'ticket_id' => $ticketId,
            'requested_by' => $actorId,
            'from_assignee' => $actorId,
            'to_assignee' => (int) $assignee['id'],
            'reason' => $reason,
            'status' => self::TRANSFER_STATUS_PENDING,
        ]);

        $formatted = $this->findTransferRequest((int) $request->id);
        $this->auditLogService->log([
            'user_id' => $actorId,
            'action' => 'support_ticket_transfer_requested',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_ticket_transfer_requests',
            'affected_id' => (int) $request->id,
            'status' => 'success',
            'data' => [
                'ticket_id' => $ticketId,
                'from_assignee' => $actorId,
                'to_assignee' => (int) $assignee['id'],
            ],
        ]);

        $targetUser = $this->loadUserById((int) $assignee['id']);
        if ($targetUser !== null) {
            $this->notifyAssignee(
                $targetUser,
                sprintf('Transfer request for ticket #%d', $ticketId),
                sprintf(
                    "A transfer request is waiting for your review.\nTicket #%d\nFrom: %s\nReason: %s",
                    $ticketId,
                    $this->actorName($actor),
                    $reason ?? 'No reason provided'
                ),
                $ticketId,
                'support_ticket_transfer_target_notified'
            );
        }

        return $formatted ?? [];
    }

    public function reviewTransferRequest(array $actor, int $requestId, array $payload): array
    {
        $requestRow = $this->findTransferRequest($requestId);
        if ($requestRow === null) {
            throw new \RuntimeException('Transfer request not found');
        }
        if (($requestRow['status'] ?? '') !== self::TRANSFER_STATUS_PENDING) {
            throw new \InvalidArgumentException('Transfer request is no longer pending');
        }

        $decision = $this->normalizeTransferStatus($payload['status'] ?? null);
        if (!in_array($decision, [self::TRANSFER_STATUS_APPROVED, self::TRANSFER_STATUS_REJECTED, self::TRANSFER_STATUS_CANCELLED], true)) {
            throw new \InvalidArgumentException('Transfer review must approve, reject, or cancel the request');
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $isRequester = $actorId > 0 && $actorId === (int) ($requestRow['requested_by'] ?? 0);
        $isTarget = $actorId > 0 && $actorId === (int) ($requestRow['to_assignee'] ?? 0);
        if ($decision === self::TRANSFER_STATUS_CANCELLED && !$isRequester) {
            throw new \DomainException('Only the transfer requester can cancel this request');
        }
        if (in_array($decision, [self::TRANSFER_STATUS_APPROVED, self::TRANSFER_STATUS_REJECTED], true) && !$isTarget) {
            throw new \DomainException('Only the transfer target can approve or reject this request');
        }

        $reviewNote = $this->nullableString($payload['review_note'] ?? null);
        $now = $this->now();

        if ($decision === self::TRANSFER_STATUS_APPROVED) {
            $ticket = $this->findTicket((int) $requestRow['ticket_id']);
            if ($ticket === null) {
                throw new \RuntimeException('Ticket not found');
            }
            $this->updateTicket((int) $requestRow['ticket_id'], [
                'assigned_to' => (int) $requestRow['to_assignee'],
                'assignment_source' => 'manual',
                'assigned_rule_id' => null,
                'assignment_locked' => 0,
                'updated_at' => $now,
            ]);
        }

        $transferRequest = SupportTicketTransferRequest::find($requestId);
        $transferRequest?->fill([
            'status' => $decision,
            'review_note' => $reviewNote,
            'reviewed_by' => $actorId,
            'reviewed_at' => $now,
        ]);
        $transferRequest?->save();

        $updatedRequest = $this->findTransferRequest($requestId);
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => 'support_ticket_transfer_reviewed',
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_ticket_transfer_requests',
            'affected_id' => $requestId,
            'status' => 'success',
            'old_data' => $requestRow,
            'new_data' => $updatedRequest,
        ]);

        return $updatedRequest ?? [];
    }

    private function listTickets(bool $includeRequester, array $baseFilters, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min($includeRequester ? 100 : 50, max(1, (int) ($query['limit'] ?? ($includeRequester ? 20 : 10))));
        $offset = ($page - 1) * $limit;

        $where = ['1 = 1'];
        $params = [];
        if (isset($baseFilters['user_id'])) {
            $where[] = 't.user_id = :user_id';
            $params['user_id'] = (int) $baseFilters['user_id'];
        }
        if (array_key_exists('assigned_to', $baseFilters)) {
            $assignedTo = $baseFilters['assigned_to'];
            if ($assignedTo === null) {
                $where[] = 't.assigned_to IS NULL';
            } else {
                $where[] = 't.assigned_to = :base_assigned_to';
                $params['base_assigned_to'] = (int) $assignedTo;
            }
        }
        if (array_key_exists('transfer_target', $baseFilters)) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM support_ticket_transfer_requests tr
                WHERE tr.ticket_id = t.id
                  AND tr.to_assignee = :transfer_target
                  AND tr.status = :transfer_status
            )';
            $params['transfer_target'] = (int) $baseFilters['transfer_target'];
            $params['transfer_status'] = self::TRANSFER_STATUS_PENDING;
        }
        if (!empty($query['status'])) {
            $where[] = 't.status = :status';
            $params['status'] = $this->normalizeStatus($query['status']);
        }
        if (!empty($query['category'])) {
            $where[] = 't.category = :category';
            $params['category'] = $this->normalizeCategory($query['category']);
        }
        if ($includeRequester && isset($query['assigned_to']) && $query['assigned_to'] !== '') {
            $assignedTo = (int) $query['assigned_to'];
            if ($assignedTo <= 0) {
                $where[] = 't.assigned_to IS NULL';
            } else {
                $where[] = 't.assigned_to = :assigned_to';
                $params['assigned_to'] = $assignedTo;
            }
        }
        if ($includeRequester && !empty($query['q'])) {
            $where[] = '(t.subject LIKE :search_subject OR requester.username LIKE :search_username OR requester.email LIKE :search_email)';
            $term = trim((string) $query['q']);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $searchPattern = '%' . $escaped . '%';
            $params['search_subject'] = $searchPattern;
            $params['search_username'] = $searchPattern;
            $params['search_email'] = $searchPattern;
        }

        $sql = "
            SELECT
                t.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                requester.uuid AS requester_uuid,
                assignee.username AS assigned_username,
                (
                    SELECT COUNT(*) FROM support_ticket_messages stm WHERE stm.ticket_id = t.id
                ) AS message_count,
                (
                    SELECT substr(stm.body, 1, 180)
                    FROM support_ticket_messages stm
                    WHERE stm.ticket_id = t.id
                    ORDER BY stm.id DESC
                    LIMIT 1
                ) AS latest_message_preview
            FROM support_tickets t
            INNER JOIN users requester ON requester.id = t.user_id
            LEFT JOIN users assignee ON assignee.id = t.assigned_to
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(t.last_replied_at, t.updated_at, t.created_at) DESC, t.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $slaSettings = $this->supportRoutingEngineService?->getSlaSettingsSnapshot();
        $items = array_map(
            fn (array $row): array => $this->formatTicketSummary($row, $includeRequester, $slaSettings),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        if ($includeRequester && $items !== [] && $this->supportAutomationService !== null) {
            $tagsByTicket = $this->supportAutomationService->getTagsForTicketIds(array_map(static fn (array $item): int => (int) $item['id'], $items));
            $items = array_map(static function (array $item) use ($tagsByTicket): array {
                $item['tags'] = array_values($tagsByTicket[(int) $item['id']] ?? []);
                return $item;
            }, $items);
        }
        if ($includeRequester && $items !== [] && $this->supportRoutingEngineService !== null) {
            $items = array_map(function (array $item): array {
                $item['routing_summary'] = $this->supportRoutingEngineService?->getRoutingSummaryForTicket((int) $item['id']);
                return $item;
            }, $items);
        }
        $countStmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM support_tickets t
            INNER JOIN users requester ON requester.id = t.user_id
            WHERE " . implode(' AND ', $where)
        );
        $countStmt->execute($params);
        return [
            'items' => $items,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $countStmt->fetchColumn()],
        ];
    }

    private function findTicketForUser(int $userId, int $ticketId): ?array
    {
        return $this->findTicket($ticketId, 'AND t.user_id = :user_id', ['user_id' => $userId]);
    }

    private function findTicketForSupport(array $actor, int $ticketId, bool $allowPendingTransferTarget = false): ?array
    {
        if ($this->isAdminActor($actor)) {
            return $this->findTicket($ticketId);
        }

        $actorId = (int) ($actor['id'] ?? 0);
        if ($actorId <= 0) {
            return null;
        }

        $assignedTicket = $this->findTicket($ticketId, 'AND t.assigned_to = :assigned_to', ['assigned_to' => $actorId]);
        if ($assignedTicket !== null || !$allowPendingTransferTarget) {
            return $assignedTicket;
        }

        return $this->findTicket(
            $ticketId,
            'AND EXISTS (
                SELECT 1
                FROM support_ticket_transfer_requests tr
                WHERE tr.ticket_id = t.id
                  AND tr.to_assignee = :transfer_target
                  AND tr.status = :transfer_status
            )',
            [
                'transfer_target' => $actorId,
                'transfer_status' => self::TRANSFER_STATUS_PENDING,
            ]
        );
    }

    private function findTicket(int $ticketId, string $extraWhere = '', array $params = []): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                requester.uuid AS requester_uuid,
                assignee.username AS assigned_username
            FROM support_tickets t
            INNER JOIN users requester ON requester.id = t.user_id
            LEFT JOIN users assignee ON assignee.id = t.assigned_to
            WHERE t.id = :ticket_id {$extraWhere}
            LIMIT 1
        ");
        $stmt->execute(['ticket_id' => $ticketId] + $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function formatTicketSummary(array $row, bool $includeRequester, ?array $slaSettings = null): array
    {
        $summary = [
            'id' => (int) $row['id'],
            'subject' => (string) $row['subject'],
            'category' => (string) $row['category'],
            'status' => (string) $row['status'],
            'priority' => (string) $row['priority'],
            'assigned_to' => isset($row['assigned_to']) ? (int) $row['assigned_to'] : null,
            'assignment_source' => $row['assignment_source'] ?? null,
            'assigned_rule_id' => isset($row['assigned_rule_id']) && $row['assigned_rule_id'] !== null ? (int) $row['assigned_rule_id'] : null,
            'assignment_locked' => !empty($row['assignment_locked']),
            'assigned_user' => $row['assigned_to'] ? ['id' => (int) $row['assigned_to'], 'username' => $row['assigned_username'] ?? null] : null,
            'last_replied_at' => $row['last_replied_at'] ?? null,
            'last_reply_by_role' => $row['last_reply_by_role'] ?? null,
            'first_support_response_at' => $row['first_support_response_at'] ?? null,
            'first_response_due_at' => $row['first_response_due_at'] ?? null,
            'resolution_due_at' => $row['resolution_due_at'] ?? null,
            'sla_status' => $row['sla_status'] ?? 'pending',
            'escalation_level' => (int) ($row['escalation_level'] ?? 0),
            'last_routing_run_id' => isset($row['last_routing_run_id']) && $row['last_routing_run_id'] !== null ? (int) $row['last_routing_run_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'closed_at' => $row['closed_at'] ?? null,
            'message_count' => (int) ($row['message_count'] ?? 0),
            'latest_message_preview' => $row['latest_message_preview'] ?? null,
        ];
        if ($this->supportRoutingEngineService !== null) {
            $summary['sla_summary'] = $this->supportRoutingEngineService->buildSlaSummaryForTicket($row, $slaSettings);
        }
        if ($includeRequester) {
            $summary['requester'] = [
                'id' => (int) ($row['user_id'] ?? 0),
                'username' => $row['requester_username'] ?? null,
                'email' => $row['requester_email'] ?? null,
                'uuid' => $row['requester_uuid'] ?? null,
            ];
        }
        return $summary;
    }

    private function formatTicketDetail(array $ticket, bool $includeRequester): array
    {
        $detail = $this->formatTicketSummary(
            $ticket,
            $includeRequester,
            $this->supportRoutingEngineService?->getSlaSettingsSnapshot()
        );
        $detail['messages'] = $this->messages((int) $ticket['id']);
        $detail['feedback_candidates'] = $this->feedbackCandidates((int) $ticket['id']);
        $detail['feedback'] = $this->feedback((int) $ticket['id']);
        if ($includeRequester && $this->supportAutomationService !== null) {
            $detail['tags'] = $this->supportAutomationService->getTagsForTicket((int) $ticket['id']);
        }
        if ($includeRequester) {
            $detail['transfer_requests'] = $this->transferRequests((int) $ticket['id']);
            $detail['routing_summary'] = $this->supportRoutingEngineService?->getRoutingSummaryForTicket((int) $ticket['id']);
        }
        return $detail;
    }

    private function messages(int $ticketId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = :ticket_id ORDER BY id ASC');
        $stmt->execute(['ticket_id' => $ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $attachments = $this->attachments(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        return array_map(static function (array $row) use ($attachments): array {
            $messageId = (int) $row['id'];
            return [
                'id' => $messageId,
                'ticket_id' => (int) $row['ticket_id'],
                'sender_id' => isset($row['sender_id']) ? (int) $row['sender_id'] : null,
                'sender_role' => $row['sender_role'] ?? null,
                'sender_name' => $row['sender_name'] ?? null,
                'body' => $row['body'] ?? '',
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'attachments' => $attachments[$messageId] ?? [],
            ];
        }, $rows);
    }

    private function feedbackCandidates(int $ticketId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT
                u.id,
                u.username,
                u.email,
                u.role,
                u.is_admin
            FROM users u
            INNER JOIN (
                SELECT sender_id AS participant_id
                FROM support_ticket_messages
                WHERE ticket_id = :message_ticket_id
                  AND sender_id IS NOT NULL
                  AND sender_role IN ('support', 'admin')
                UNION
                SELECT assigned_to AS participant_id
                FROM support_tickets
                WHERE id = :assigned_ticket_id
                  AND assigned_to IS NOT NULL
            ) participants ON participants.participant_id = u.id
            WHERE u.deleted_at IS NULL
              AND (u.is_admin = 1 OR LOWER(COALESCE(u.role, 'user')) IN ('support', 'admin'))
            ORDER BY COALESCE(u.username, u.email, ''), u.id
        ");
        $stmt->execute([
            'message_ticket_id' => $ticketId,
            'assigned_ticket_id' => $ticketId,
        ]);

        return array_map(static function (array $row): array {
            $role = !empty($row['is_admin']) ? 'admin' : strtolower((string) ($row['role'] ?? 'support'));
            return [
                'id' => (int) ($row['id'] ?? 0),
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'role' => $role,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function findFeedbackCandidate(int $ticketId, int $ratedUserId): ?array
    {
        foreach ($this->feedbackCandidates($ticketId) as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $ratedUserId) {
                return $candidate;
            }
        }

        return null;
    }

    private function feedback(int $ticketId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                f.*,
                reviewer.username AS reviewer_username,
                reviewer.email AS reviewer_email,
                rated.username AS rated_username,
                rated.email AS rated_email,
                rated.role AS rated_role,
                rated.is_admin AS rated_is_admin
            FROM support_ticket_feedback f
            INNER JOIN users reviewer ON reviewer.id = f.user_id
            INNER JOIN users rated ON rated.id = f.rated_user_id
            WHERE f.ticket_id = :ticket_id
            ORDER BY f.id ASC
        ");
        $stmt->execute(['ticket_id' => $ticketId]);

        return array_map(static function (array $row): array {
            $ratedRole = !empty($row['rated_is_admin']) ? 'admin' : strtolower((string) ($row['rated_role'] ?? 'support'));
            return [
                'id' => (int) ($row['id'] ?? 0),
                'ticket_id' => (int) ($row['ticket_id'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'rated_user_id' => (int) ($row['rated_user_id'] ?? 0),
                'rating' => (int) ($row['rating'] ?? 0),
                'comment' => $row['comment'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'reviewer' => [
                    'id' => (int) ($row['user_id'] ?? 0),
                    'username' => $row['reviewer_username'] ?? null,
                    'email' => $row['reviewer_email'] ?? null,
                ],
                'rated_user' => [
                    'id' => (int) ($row['rated_user_id'] ?? 0),
                    'username' => $row['rated_username'] ?? null,
                    'email' => $row['rated_email'] ?? null,
                    'role' => $ratedRole,
                ],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function findFeedbackRecord(int $ticketId, int $userId, int $ratedUserId): ?SupportTicketFeedback
    {
        return SupportTicketFeedback::query()
            ->where('ticket_id', $ticketId)
            ->where('user_id', $userId)
            ->where('rated_user_id', $ratedUserId)
            ->first();
    }

    private function attachments(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $sql = 'SELECT * FROM support_ticket_attachments WHERE message_id IN (' . implode(',', array_fill(0, count($messageIds), '?')) . ') ORDER BY id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($messageIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $messageId = (int) $row['message_id'];
            $result[$messageId][] = [
                'id' => (int) $row['id'],
                'ticket_id' => (int) $row['ticket_id'],
                'message_id' => $messageId,
                'file_id' => isset($row['file_id']) ? (int) $row['file_id'] : null,
                'file_path' => $row['file_path'],
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size' => (int) ($row['size'] ?? 0),
                'entity_type' => $row['entity_type'] ?? 'support_ticket_message',
                'download_url' => $this->presignedUrl($row['file_path'] ?? null),
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        return $result;
    }

    private function transferRequests(int $ticketId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                tr.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                from_user.username AS from_username,
                from_user.email AS from_email,
                to_user.username AS to_username,
                to_user.email AS to_email,
                reviewer.username AS reviewer_username,
                reviewer.email AS reviewer_email
            FROM support_ticket_transfer_requests tr
            INNER JOIN users requester ON requester.id = tr.requested_by
            LEFT JOIN users from_user ON from_user.id = tr.from_assignee
            LEFT JOIN users to_user ON to_user.id = tr.to_assignee
            LEFT JOIN users reviewer ON reviewer.id = tr.reviewed_by
            WHERE tr.ticket_id = :ticket_id
            ORDER BY tr.id DESC
        ");
        $stmt->execute(['ticket_id' => $ticketId]);

        return array_map(fn (array $row): array => $this->formatTransferRequest($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function attachFiles(int $ticketId, int $messageId, array $paths, int $actorUserId, bool $supportActor): void
    {
        foreach ($paths as $path) {
            $file = $this->fileMetadataService->findByFilePath($path);
            if ($file === null) {
                throw new \InvalidArgumentException('Attachment not found: ' . $path);
            }
            if (!$supportActor && (int) ($file->user_id ?? 0) !== $actorUserId) {
                throw new \InvalidArgumentException('Attachment ownership mismatch: ' . $path);
            }
            if ($supportActor && !$this->canSupportActorAttachFile($ticketId, $path, (int) ($file->user_id ?? 0), $actorUserId)) {
                throw new \InvalidArgumentException('Attachment is not authorized for this ticket: ' . $path);
            }
            SupportTicketAttachment::create([
                'ticket_id' => $ticketId,
                'message_id' => $messageId,
                'file_id' => (int) ($file->id ?? 0) ?: null,
                'file_path' => (string) $file->file_path,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => (int) ($file->size ?? 0),
                'entity_type' => 'support_ticket_message',
                'created_at' => $this->now(),
            ]);
        }
    }

    private function canSupportActorAttachFile(int $ticketId, string $path, int $fileOwnerUserId, int $actorUserId): bool
    {
        if ($fileOwnerUserId > 0 && $fileOwnerUserId === $actorUserId) {
            return true;
        }

        $stmt = $this->db->prepare('
            SELECT 1
            FROM support_ticket_attachments
            WHERE ticket_id = :ticket_id
              AND file_path = :file_path
            LIMIT 1
        ');
        $stmt->execute([
            'ticket_id' => $ticketId,
            'file_path' => $path,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    private function updateTicket(int $ticketId, array $fields): void
    {
        $set = [];
        $params = ['id' => $ticketId];
        foreach ($fields as $field => $value) {
            $set[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
        $stmt = $this->db->prepare('UPDATE support_tickets SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    private function findTransferRequest(int $requestId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                tr.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                from_user.username AS from_username,
                from_user.email AS from_email,
                to_user.username AS to_username,
                to_user.email AS to_email,
                reviewer.username AS reviewer_username,
                reviewer.email AS reviewer_email
            FROM support_ticket_transfer_requests tr
            INNER JOIN users requester ON requester.id = tr.requested_by
            LEFT JOIN users from_user ON from_user.id = tr.from_assignee
            LEFT JOIN users to_user ON to_user.id = tr.to_assignee
            LEFT JOIN users reviewer ON reviewer.id = tr.reviewed_by
            WHERE tr.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->formatTransferRequest($row) : null;
    }

    private function findPendingTransferRequestForTicket(int $ticketId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id
            FROM support_ticket_transfer_requests
            WHERE ticket_id = :ticket_id AND status = :status
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([
            'ticket_id' => $ticketId,
            'status' => self::TRANSFER_STATUS_PENDING,
        ]);
        $requestId = $stmt->fetchColumn();

        return $requestId ? $this->findTransferRequest((int) $requestId) : null;
    }

    private function formatTransferRequest(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'ticket_id' => (int) ($row['ticket_id'] ?? 0),
            'requested_by' => (int) ($row['requested_by'] ?? 0),
            'from_assignee' => isset($row['from_assignee']) ? (int) $row['from_assignee'] : null,
            'to_assignee' => (int) ($row['to_assignee'] ?? 0),
            'reason' => $row['reason'] ?? null,
            'status' => (string) ($row['status'] ?? self::TRANSFER_STATUS_PENDING),
            'review_note' => $row['review_note'] ?? null,
            'reviewed_by' => isset($row['reviewed_by']) ? (int) $row['reviewed_by'] : null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'requester' => [
                'id' => (int) ($row['requested_by'] ?? 0),
                'username' => $row['requester_username'] ?? null,
                'email' => $row['requester_email'] ?? null,
            ],
            'from_user' => ($row['from_assignee'] ?? null) !== null ? [
                'id' => (int) $row['from_assignee'],
                'username' => $row['from_username'] ?? null,
                'email' => $row['from_email'] ?? null,
            ] : null,
            'to_user' => [
                'id' => (int) ($row['to_assignee'] ?? 0),
                'username' => $row['to_username'] ?? null,
                'email' => $row['to_email'] ?? null,
            ],
            'reviewer' => ($row['reviewed_by'] ?? null) !== null ? [
                'id' => (int) $row['reviewed_by'],
                'username' => $row['reviewer_username'] ?? null,
                'email' => $row['reviewer_email'] ?? null,
            ] : null,
        ];
    }

    private function findAssignableUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, role, is_admin FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $role = strtolower((string) ($row['role'] ?? 'user'));
        return (!empty($row['is_admin']) || in_array($role, ['support', 'admin'], true)) ? $row : null;
    }

    private function loadUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, email, role, is_admin FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function notifyAssignee(array $user, string $subject, string $body, int $ticketId, string $auditAction): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $messageSent = false;
        $emailSent = false;

        if ($this->messageService !== null && $userId > 0) {
            try {
                $this->messageService->sendSystemMessage(
                    $userId,
                    $subject,
                    $body,
                    'support_ticket',
                    'normal',
                    'support_ticket',
                    $ticketId,
                    false
                );
                $messageSent = true;
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to send support assignee system notification', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
                $this->recordNotificationFailure($exception, 'support_assignee_system_notification_failed', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'subject' => $subject,
                ]);
            }
        }

        if ($this->emailService !== null && !empty($user['email'])) {
            try {
                $this->emailService->sendMessageNotification(
                    (string) $user['email'],
                    (string) ($user['username'] ?? $user['email']),
                    $subject,
                    $body,
                    NotificationPreferenceService::CATEGORY_SUPPORT,
                    'normal'
                );
                $emailSent = true;
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to send support assignee email notification', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
                $this->recordNotificationFailure($exception, 'support_assignee_email_notification_failed', [
                    'ticket_id' => $ticketId,
                    'user_id' => $userId,
                    'subject' => $subject,
                ]);
            }
        }

        $this->auditLogService->log([
            'user_id' => $userId > 0 ? $userId : null,
            'action' => $auditAction,
            'operation_category' => 'support',
            'actor_type' => 'system',
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => ($messageSent || $emailSent) ? 'success' : 'partial',
            'data' => [
                'message_sent' => $messageSent,
                'email_sent' => $emailSent,
                'subject' => $subject,
            ],
        ]);
    }

    private function notifySupportMailbox(string $subject, string $body): void
    {
        if ($this->emailService === null) {
            return;
        }
        $supportEmail = trim((string) $this->emailService->getSupportEmail());
        if ($supportEmail === '') {
            return;
        }
        try {
            $this->emailService->sendMessageNotification($supportEmail, 'Support Team', $subject, $body, NotificationPreferenceService::CATEGORY_MESSAGE, 'high');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send support mailbox notification', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }

    private function notifyUserReply(array $ticket, string $body, int $ticketId): void
    {
        $userId = (int) ($ticket['user_id'] ?? 0);
        $messageBody = "Your support ticket has a new reply.\n\n" . $body;
        if ($this->messageService !== null && $userId > 0) {
            try {
                $this->messageService->sendSystemMessage($userId, 'Support replied to your ticket', $messageBody, 'message', 'normal', 'support_ticket', $ticketId, false);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send support reply message', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            }
        }
        if ($this->emailService !== null && !empty($ticket['requester_email'])) {
            try {
                $this->emailService->sendMessageNotification(
                    (string) $ticket['requester_email'],
                    (string) ($ticket['requester_username'] ?? $ticket['requester_email']),
                    sprintf('Support replied to ticket #%d', $ticketId),
                    $messageBody,
                    NotificationPreferenceService::CATEGORY_MESSAGE,
                    'normal'
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send support reply email', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notifyUserTicketUpdated(array $ticket, array $updates, int $ticketId): void
    {
        $summary = $this->buildTicketUpdateSummary($ticket, $updates);
        if ($summary === '') {
            return;
        }

        $userId = (int) ($ticket['user_id'] ?? 0);
        $subject = sprintf('Support ticket #%d updated', $ticketId);
        $messageBody = "Your support ticket has been updated.\n\n" . $summary;

        if ($this->messageService !== null && $userId > 0) {
            try {
                $this->messageService->sendSystemMessage(
                    $userId,
                    $subject,
                    $messageBody,
                    'support_ticket',
                    'normal',
                    'support_ticket',
                    $ticketId,
                    false
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send support ticket update message', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            }
        }

        if ($this->emailService !== null && !empty($ticket['requester_email'])) {
            try {
                $this->emailService->sendMessageNotification(
                    (string) $ticket['requester_email'],
                    (string) ($ticket['requester_username'] ?? $ticket['requester_email']),
                    $subject,
                    $messageBody,
                    NotificationPreferenceService::CATEGORY_SUPPORT,
                    'normal'
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send support ticket update email', ['ticket_id' => $ticketId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function supportMailboxBody(array $actor, array $ticket, string $body): string
    {
        return sprintf(
            "Ticket #%d\nUser: %s <%s>\nCategory: %s\nPriority: %s\nStatus: %s\n\n%s",
            (int) ($ticket['id'] ?? 0),
            $this->actorName($actor),
            (string) ($actor['email'] ?? ''),
            (string) ($ticket['category'] ?? ''),
            (string) ($ticket['priority'] ?? 'normal'),
            (string) ($ticket['status'] ?? self::STATUS_OPEN),
            $body
        );
    }

    private function buildTicketUpdateSummary(array $ticket, array $updates): string
    {
        $changes = [];

        if (array_key_exists('status', $updates)) {
            $changes[] = sprintf(
                'Status: %s -> %s',
                (string) ($ticket['status'] ?? 'unknown'),
                (string) ($updates['status'] ?? 'unknown')
            );
        }

        if (array_key_exists('priority', $updates)) {
            $changes[] = sprintf(
                'Priority: %s -> %s',
                (string) ($ticket['priority'] ?? 'unknown'),
                (string) ($updates['priority'] ?? 'unknown')
            );
        }

        if (array_key_exists('assigned_to', $updates)) {
            $previous = !empty($ticket['assigned_username'])
                ? (string) $ticket['assigned_username']
                : ((isset($ticket['assigned_to']) && $ticket['assigned_to'] !== null) ? 'User #' . (int) $ticket['assigned_to'] : 'Unassigned');
            $next = $updates['assigned_to'] === null
                ? 'Unassigned'
                : 'User #' . (int) $updates['assigned_to'];
            $changes[] = sprintf('Assigned handler: %s -> %s', $previous, $next);
        }

        if ($changes === []) {
            return '';
        }

        return implode("\n", $changes);
    }

    private function presignedUrl(?string $filePath): ?string
    {
        if (!$this->r2Service || !is_string($filePath) || trim($filePath) === '') {
            return null;
        }
        try {
            return $this->r2Service->generatePresignedUrl($filePath, 900);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to build support ticket file URL', ['file_path' => $filePath, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function normalizeAttachments(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }
        $paths = [];
        foreach ($attachments as $attachment) {
            if (is_string($attachment) && trim($attachment) !== '') {
                $paths[] = trim($attachment);
                continue;
            }
            if (is_array($attachment)) {
                $path = $attachment['file_path'] ?? $attachment['path'] ?? null;
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = trim($path);
                }
            }
        }
        return array_values(array_unique($paths));
    }

    private function normalizeCategory(mixed $value): string
    {
        $category = is_string($value) ? trim($value) : '';
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            throw new \InvalidArgumentException('Invalid category');
        }
        return $category;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = is_string($value) ? trim($value) : '';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status');
        }
        return $status;
    }

    private function normalizePriority(mixed $value): string
    {
        $priority = is_string($value) && trim($value) !== '' ? trim($value) : 'normal';
        if (!in_array($priority, self::VALID_PRIORITIES, true)) {
            throw new \InvalidArgumentException('Invalid priority');
        }
        return $priority;
    }

    private function normalizeTransferStatus(mixed $value): string
    {
        $status = is_string($value) ? trim($value) : '';
        if (!in_array($status, self::VALID_TRANSFER_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid transfer status');
        }
        return $status;
    }

    private function normalizeRating(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('rating is required');
        }

        $rating = (int) $value;
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('rating must be between 1 and 5');
        }

        return $rating;
    }

    private function normalizeFeedbackComment(mixed $value): ?string
    {
        $comment = $this->nullableString($value);
        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw new \InvalidArgumentException('comment must be 1000 characters or fewer');
        }

        return $comment;
    }

    private function requireString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s is required', $field));
        }
        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function actorName(array $actor): string
    {
        $name = trim((string) ($actor['username'] ?? ''));
        return $name !== '' ? $name : ((string) ($actor['email'] ?? 'User'));
    }

    private function actorType(array $actor): string
    {
        if ($this->isAdminActor($actor)) {
            return 'admin';
        }
        if (!empty($actor['is_support']) || (($actor['role'] ?? null) === 'support')) {
            return 'support';
        }
        return 'user';
    }

    private function isAdminActor(array $actor): bool
    {
        return !empty($actor['is_admin']) || (($actor['role'] ?? null) === 'admin');
    }

    private function supportTicketBaseFilters(array $actor, array $query = []): array
    {
        $actorId = (int) ($actor['id'] ?? 0);
        if ($actorId <= 0) {
            return ['assigned_to' => -1];
        }

        if ($this->isPendingTransferTargetQuery($actor, $query)) {
            return ['transfer_target' => $actorId];
        }

        if ($this->isAdminActor($actor)) {
            return [];
        }

        return ['assigned_to' => $actorId];
    }

    private function supportTicketQuery(array $actor, array $query): array
    {
        if ($this->isAdminActor($actor)) {
            return $query;
        }

        unset($query['assigned_to']);
        unset($query['pending_transfer_target']);
        return $query;
    }

    private function isPendingTransferTargetQuery(array $actor, array $query): bool
    {
        $raw = $query['pending_transfer_target'] ?? null;
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw) || (is_string($raw) && is_numeric($raw))) {
            return (int) $raw === 1;
        }
        if (is_string($raw)) {
            return in_array(strtolower(trim($raw)), ['true', 'yes', 'on'], true);
        }

        return false;
    }

    private function pendingTransferRequestsForTarget(array $ticketIds, int $targetUserId): array
    {
        $ticketIds = array_values(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0));
        if ($ticketIds === [] || $targetUserId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $stmt = $this->db->prepare("
            SELECT
                tr.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                from_user.username AS from_username,
                from_user.email AS from_email,
                to_user.username AS to_username,
                to_user.email AS to_email,
                reviewer.username AS reviewer_username,
                reviewer.email AS reviewer_email
            FROM support_ticket_transfer_requests tr
            INNER JOIN users requester ON requester.id = tr.requested_by
            LEFT JOIN users from_user ON from_user.id = tr.from_assignee
            LEFT JOIN users to_user ON to_user.id = tr.to_assignee
            LEFT JOIN users reviewer ON reviewer.id = tr.reviewed_by
            WHERE tr.ticket_id IN ({$placeholders})
              AND tr.to_assignee = ?
              AND tr.status = ?
            ORDER BY tr.id DESC
        ");
        $stmt->execute([
            ...$ticketIds,
            $targetUserId,
            self::TRANSFER_STATUS_PENDING,
        ]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ticketId = (int) ($row['ticket_id'] ?? 0);
            if ($ticketId > 0 && !isset($result[$ticketId])) {
                $result[$ticketId] = $this->formatTransferRequest($row);
            }
        }

        return $result;
    }

    private function recordFailure(\Throwable $e, string $action, array $actor, ?int $ticketId): void
    {
        $this->auditLogService->log([
            'user_id' => isset($actor['id']) ? (int) $actor['id'] : null,
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => $this->actorType($actor),
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => 'failed',
            'data' => ['error' => $e->getMessage()],
        ]);
    }

    private function recordNotificationFailure(\Throwable $exception, string $action, array $context): void
    {
        $this->auditLogService->log([
            'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : null,
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => 'system',
            'affected_table' => 'support_tickets',
            'affected_id' => isset($context['ticket_id']) ? (int) $context['ticket_id'] : null,
            'status' => 'failed',
            'data' => $context + ['error' => $exception->getMessage()],
        ]);

        try {
            $request = \CarbonTrack\Support\SyntheticRequestFactory::fromContext(
                '/support/notifications',
                'SYSTEM',
                null,
                [],
                $context,
                ['PHP_SAPI' => PHP_SAPI]
            );
            $this->errorLogService->logException($exception, $request, $context);
        } catch (\Throwable) {
            // ignore secondary logging failure
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
