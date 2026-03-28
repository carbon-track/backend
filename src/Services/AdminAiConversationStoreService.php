<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Psr\Log\LoggerInterface;

class AdminAiConversationStoreService
{
    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
        private ?AuditLogService $auditLogService = null
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listConversations(array $filters = []): array
    {
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 20)));
        $actorId = isset($filters['actor_id']) && is_numeric((string) $filters['actor_id'])
            ? (int) $filters['actor_id']
            : (isset($filters['admin_id']) && is_numeric((string) $filters['admin_id']) ? (int) $filters['admin_id'] : null);
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $model = isset($filters['model']) ? trim((string) $filters['model']) : null;
        $dateFrom = $this->normalizeDateBoundary($filters['date_from'] ?? null, false);
        $dateTo = $this->normalizeDateBoundary($filters['date_to'] ?? null, true);
        $hasPendingAction = $this->normalizeBooleanFilter($filters['has_pending_action'] ?? null);
        $conversationIdFilter = $this->normalizeConversationId(isset($filters['conversation_id']) ? (string) $filters['conversation_id'] : null);

        $sql = "SELECT
                    c.conversation_id,
                    c.started_at,
                    c.last_activity_at,
                    c.admin_id,
                    c.title,
                    c.last_message_preview,
                    COALESCE(msg.message_count, 0) AS message_count,
                    COALESCE(pending.pending_action_count, 0) AS pending_action_count,
                    COALESCE(llm.llm_calls, 0) AS llm_calls,
                    COALESCE(llm.total_tokens, 0) AS total_tokens,
                    llm.last_model
                FROM admin_ai_conversations c
                LEFT JOIN (
                    SELECT conversation_id, COUNT(*) AS message_count
                    FROM admin_ai_messages
                    WHERE kind = 'message'
                    GROUP BY conversation_id
                ) msg ON msg.conversation_id = c.conversation_id
                LEFT JOIN (
                    SELECT conversation_id, COUNT(*) AS pending_action_count
                    FROM admin_ai_messages
                    WHERE kind = 'action_proposed' AND status = 'pending'
                    GROUP BY conversation_id
                ) pending ON pending.conversation_id = c.conversation_id
                LEFT JOIN (
                    SELECT
                        logs.conversation_id,
                        COUNT(*) AS llm_calls,
                        COALESCE(SUM(logs.total_tokens), 0) AS total_tokens,
                        (
                            SELECT latest.model
                            FROM llm_logs latest
                            WHERE latest.conversation_id = logs.conversation_id
                            ORDER BY latest.created_at DESC, latest.id DESC
                            LIMIT 1
                        ) AS last_model
                    FROM llm_logs logs
                    WHERE logs.conversation_id IS NOT NULL
                      AND logs.conversation_id <> ''
                    GROUP BY logs.conversation_id
                ) llm ON llm.conversation_id = c.conversation_id
                WHERE 1 = 1";
        /** @var array<string,array{0:mixed,1:int}> $params */
        $params = [];
        if ($actorId !== null) {
            $sql .= " AND c.admin_id = :actor_id";
            $params[':actor_id'] = [$actorId, PDO::PARAM_INT];
        }
        if ($conversationIdFilter !== null) {
            $sql .= " AND c.conversation_id = :conversation_id";
            $params[':conversation_id'] = [$conversationIdFilter, PDO::PARAM_STR];
        }
        if ($dateFrom !== null) {
            $sql .= " AND c.last_activity_at >= :date_from";
            $params[':date_from'] = [$dateFrom, PDO::PARAM_STR];
        }
        if ($dateTo !== null) {
            $sql .= " AND c.last_activity_at <= :date_to";
            $params[':date_to'] = [$dateTo, PDO::PARAM_STR];
        }
        if ($model !== null && $model !== '') {
            $sql .= " AND llm.last_model LIKE :model";
            $params[':model'] = ['%' . $model . '%', PDO::PARAM_STR];
        }
        if ($status === 'waiting_confirmation') {
            $sql .= " AND COALESCE(pending.pending_action_count, 0) > 0";
        } elseif ($status === 'active') {
            $sql .= " AND COALESCE(pending.pending_action_count, 0) = 0";
        }
        if ($hasPendingAction === true) {
            $sql .= " AND COALESCE(pending.pending_action_count, 0) > 0";
        } elseif ($hasPendingAction === false) {
            $sql .= " AND COALESCE(pending.pending_action_count, 0) = 0";
        }

        $sql .= " ORDER BY c.last_activity_at DESC LIMIT :limit";

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => [$value, $type]) {
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $conversationId = (string) ($row['conversation_id'] ?? '');
                if ($conversationId === '') {
                    continue;
                }

                $pendingCount = (int) ($row['pending_action_count'] ?? 0);
                $items[] = [
                    'conversation_id' => $conversationId,
                    'started_at' => $row['started_at'] ?? null,
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                    'admin_id' => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
                    'message_count' => (int) ($row['message_count'] ?? 0),
                    'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                    'llm_calls' => (int) ($row['llm_calls'] ?? 0),
                    'last_model' => $row['last_model'] ?? null,
                    'status' => $pendingCount > 0 ? 'waiting_confirmation' : 'active',
                    'pending_action_count' => $pendingCount,
                    'title' => $row['title'] ?? null,
                    'last_message_preview' => $row['last_message_preview'] ?? null,
                ];
            }

            return $items;
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to list admin AI conversations from dedicated store.', [
                'error' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getConversationDetail(string $conversationId): array
    {
        $conversationId = $this->normalizeConversationId($conversationId) ?? '';
        if ($conversationId === '') {
            throw new \InvalidArgumentException('Invalid conversation id');
        }

        $messages = $this->fetchConversationTimeline($conversationId);
        $totals = $this->fetchConversationTokenSummary($conversationId);
        $pendingActions = array_values(array_filter(array_map(
            static fn (array $item): ?array => ($item['kind'] ?? null) === 'action_proposed' && ($item['status'] ?? null) === 'pending'
                ? ($item['proposal'] ?? null)
                : null,
            $messages
        )));

        $title = null;
        $startedAt = null;
        $lastActivityAt = null;
        $messageCount = 0;
        foreach ($messages as $item) {
            $createdAt = $item['created_at'] ?? null;
            if ($createdAt !== null && ($startedAt === null || $createdAt < $startedAt)) {
                $startedAt = $createdAt;
            }
            if ($createdAt !== null && ($lastActivityAt === null || $createdAt > $lastActivityAt)) {
                $lastActivityAt = $createdAt;
            }
            if (($item['kind'] ?? null) === 'message') {
                $messageCount++;
                if ($title === null && ($item['role'] ?? null) === 'user') {
                    $title = $this->buildPreview((string) ($item['content'] ?? ''), 80);
                }
            }
        }

        return [
            'conversation_id' => $conversationId,
            'summary' => [
                'title' => $title,
                'started_at' => $startedAt,
                'last_activity_at' => $lastActivityAt,
                'message_count' => $messageCount,
                'pending_action_count' => count($pendingActions),
                'status' => count($pendingActions) > 0 ? 'waiting_confirmation' : 'active',
                'total_tokens' => (int) ($totals['total_tokens'] ?? 0),
                'llm_calls' => (int) ($totals['llm_calls'] ?? 0),
                'last_model' => $totals['last_model'] ?? null,
            ],
            'messages' => $messages,
            'llm_calls' => $this->fetchConversationLlmCalls($conversationId),
            'pending_actions' => $pendingActions,
        ];
    }

    /**
     * @return array<int,array{role:string,content:string}>
     */
    public function fetchHistoryMessages(string $conversationId, int $maxHistory = 12): array
    {
        $timeline = $this->fetchStoredConversationTimeline($conversationId);
        $history = [];
        foreach ($timeline as $item) {
            if (($item['kind'] ?? null) !== 'message') {
                continue;
            }
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $history[] = [
                'role' => ($item['role'] ?? null) === 'user' ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        $maxHistory = max(2, $maxHistory);
        return count($history) > $maxHistory ? array_slice($history, -$maxHistory) : $history;
    }

    public function getNextTurnNo(string $conversationId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(turn_no), 0) FROM llm_logs WHERE conversation_id = :conversation_id');
        $stmt->execute([':conversation_id' => $conversationId]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findProposal(string $conversationId, int $proposalId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM admin_ai_messages
                WHERE id = :id
                  AND conversation_id = :conversation_id
                  AND kind = 'action_proposed'");
            $stmt->execute([':id' => $proposalId, ':conversation_id' => $conversationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $meta = $this->decodeJson($row['meta_json'] ?? null);
                $data = isset($meta['data']) && is_array($meta['data']) ? $meta['data'] : $meta;
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'status' => $row['status'] ?? null,
                    'action_name' => $data['action_name'] ?? null,
                    'payload' => $data['payload'] ?? null,
                ];
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to load admin AI proposal from dedicated store.', [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function updateProposalStatus(int $proposalId, string $status, array $meta = []): void
    {
        try {
            $stmt = $this->db->prepare("SELECT meta_json FROM admin_ai_messages WHERE id = :id");
            $stmt->execute([':id' => $proposalId]);
            $existingRaw = $stmt->fetchColumn();
            if ($existingRaw !== false) {
                $existing = $this->decodeJson(is_string($existingRaw) ? $existingRaw : null);
                $existing['data'] = isset($existing['data']) && is_array($existing['data']) ? $existing['data'] : [];
                $existing['data']['decision_meta'] = $meta;

                $update = $this->db->prepare("UPDATE admin_ai_messages
                    SET status = :status, meta_json = :meta_json, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $update->execute([
                    ':status' => $status,
                    ':meta_json' => json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':id' => $proposalId,
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to update admin AI proposal in dedicated store.', [
                'proposal_id' => $proposalId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $logContext
     * @param array<string,mixed> $payload
     */
    public function logConversationEvent(string $action, array $logContext, array $payload): ?int
    {
        $conversationId = $payload['conversation_id'] ?? ($logContext['conversation_id'] ?? null);
        $visibleText = isset($payload['visible_text']) ? trim((string) $payload['visible_text']) : null;
        $requestPayload = isset($payload['request_data']) && is_array($payload['request_data']) ? $payload['request_data'] : [];

        $requestData = array_filter([
            'conversation_id' => $conversationId,
            'visible_text' => $visibleText,
            'role' => $payload['role'] ?? null,
            'tool_name' => $payload['tool_name'] ?? null,
            'action_name' => $payload['action_name'] ?? ($requestPayload['action_name'] ?? null),
            'label' => $payload['label'] ?? ($requestPayload['label'] ?? null),
            'summary' => $payload['summary'] ?? ($requestPayload['summary'] ?? null),
            'payload' => isset($payload['payload']) && is_array($payload['payload'])
                ? $payload['payload']
                : (isset($requestPayload['payload']) && is_array($requestPayload['payload']) ? $requestPayload['payload'] : null),
            'proposal_id' => isset($payload['proposal_id']) ? (int) $payload['proposal_id'] : null,
            'risk_level' => $payload['risk_level'] ?? ($requestPayload['risk_level'] ?? null),
            'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
            'suggestion' => isset($payload['suggestion']) && is_array($payload['suggestion']) ? $payload['suggestion'] : null,
            'proposal' => isset($payload['proposal']) && is_array($payload['proposal']) ? $payload['proposal'] : null,
            'result' => isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($requestPayload !== []) {
            $requestData = array_merge($requestData, ['request_payload' => $requestPayload]);
        }

        $storedMessageId = $this->storeConversationEvent($action, $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $visibleText,
            'status' => $payload['status'] ?? 'success',
            'request_data' => $requestData,
            'response_code' => $payload['response_code'] ?? null,
        ]);

        if ($this->auditLogService !== null) {
            try {
                $this->auditLogService->logAdminOperation(
                    $action,
                    isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null,
                    'admin_ai',
                    [
                        'request_id' => $logContext['request_id'] ?? null,
                        'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
                        'request_method' => 'POST',
                        'status' => $payload['status'] ?? 'success',
                        'conversation_id' => is_string($conversationId) ? $conversationId : null,
                        'request_data' => $requestData,
                        'new_data' => isset($payload['new_data']) && is_array($payload['new_data']) ? $payload['new_data'] : null,
                        'record_id' => isset($payload['proposal_id']) ? (int) $payload['proposal_id'] : $storedMessageId,
                        'table' => 'admin_ai_messages',
                    ]
                );
            } catch (\Throwable $exception) {
                $this->logger->warning('Failed to write admin AI conversation audit log.', [
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $storedMessageId;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchConversationTimeline(string $conversationId): array
    {
        return $this->fetchStoredConversationTimeline($conversationId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchConversationLlmCalls(string $conversationId): array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM llm_logs
            WHERE conversation_id = :conversation_id
            ORDER BY COALESCE(turn_no, 0) ASC, created_at ASC, id ASC");
        $stmt->execute([':conversation_id' => $conversationId]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'turn_no' => $row['turn_no'] !== null ? (int) $row['turn_no'] : null,
                'request_id' => $row['request_id'] ?? null,
                'model' => $row['model'] ?? null,
                'status' => $row['status'] ?? null,
                'total_tokens' => $row['total_tokens'] !== null ? (int) $row['total_tokens'] : null,
                'latency_ms' => $row['latency_ms'] !== null ? (float) $row['latency_ms'] : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchConversationTokenSummary(string $conversationId): array
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS llm_calls, COALESCE(SUM(total_tokens), 0) AS total_tokens
            FROM llm_logs WHERE conversation_id = :conversation_id");
        $stmt->execute([':conversation_id' => $conversationId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $modelStmt = $this->db->prepare("SELECT model FROM llm_logs
            WHERE conversation_id = :conversation_id
            ORDER BY created_at DESC, id DESC LIMIT 1");
        $modelStmt->execute([':conversation_id' => $conversationId]);

        return [
            'llm_calls' => (int) ($summary['llm_calls'] ?? 0),
            'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
            'last_model' => $modelStmt->fetchColumn() ?: null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function storeConversationEvent(string $action, array $logContext, array $payload): ?int
    {
        $conversationId = $this->normalizeConversationId(isset($payload['conversation_id']) ? (string) $payload['conversation_id'] : null);
        if ($conversationId === null) {
            return null;
        }

        $kind = $this->mapConversationActionToKind($action);
        $role = $this->mapConversationActionToRole($action);
        $content = isset($payload['visible_text']) ? trim((string) $payload['visible_text']) : null;
        $requestData = isset($payload['request_data']) && is_array($payload['request_data']) ? $payload['request_data'] : [];
        $status = isset($payload['status']) ? trim((string) $payload['status']) : 'success';
        $responseCode = isset($payload['response_code']) && is_numeric((string) $payload['response_code'])
            ? (int) $payload['response_code']
            : null;

        $metaJson = json_encode([
            'request_id' => $logContext['request_id'] ?? null,
            'response_code' => $responseCode,
            'data' => $requestData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $this->ensureConversationRecord(
                $conversationId,
                isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null,
                $content,
                $kind,
                $role
            );

            $stmt = $this->db->prepare("INSERT INTO admin_ai_messages (
                conversation_id, kind, role, action, status, content, request_id, response_code, meta_json
            ) VALUES (
                :conversation_id, :kind, :role, :action, :status, :content, :request_id, :response_code, :meta_json
            )");
            $stmt->execute([
                ':conversation_id' => $conversationId,
                ':kind' => $kind,
                ':role' => $role,
                ':action' => $action,
                ':status' => $status !== '' ? $status : 'success',
                ':content' => $content !== '' ? $content : null,
                ':request_id' => $logContext['request_id'] ?? null,
                ':response_code' => $responseCode,
                ':meta_json' => $metaJson,
            ]);

            $messageId = (int) $this->db->lastInsertId();
            $this->touchConversationRecord($conversationId, $content, $kind, $role);
            return $messageId > 0 ? $messageId : null;
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to write admin AI conversation store record.', [
                'action' => $action,
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    private function ensureConversationRecord(
        string $conversationId,
        ?int $adminId,
        ?string $content,
        string $kind,
        ?string $role
    ): void {
        $title = $kind === 'message' && $role === 'user' ? $this->buildPreview($content, 80) : null;
        $preview = $kind === 'message' ? $this->buildPreview($content, 120) : null;
        $existsStmt = $this->db->prepare("SELECT id FROM admin_ai_conversations WHERE conversation_id = :conversation_id LIMIT 1");
        $existsStmt->execute([':conversation_id' => $conversationId]);
        $exists = $existsStmt->fetchColumn();

        if ($exists === false) {
            $insert = $this->db->prepare("INSERT INTO admin_ai_conversations (
                conversation_id, admin_id, title, last_message_preview
            ) VALUES (
                :conversation_id, :admin_id, :title, :last_message_preview
            )");
            $insert->execute([
                ':conversation_id' => $conversationId,
                ':admin_id' => $adminId,
                ':title' => $title,
                ':last_message_preview' => $preview,
            ]);
            return;
        }

        $update = $this->db->prepare("UPDATE admin_ai_conversations
            SET
                admin_id = COALESCE(admin_id, :admin_id),
                title = COALESCE(title, :title),
                last_message_preview = COALESCE(:last_message_preview, last_message_preview),
                last_activity_at = CURRENT_TIMESTAMP
            WHERE conversation_id = :conversation_id");
        $update->execute([
            ':admin_id' => $adminId,
            ':title' => $title,
            ':last_message_preview' => $preview,
            ':conversation_id' => $conversationId,
        ]);
    }

    private function touchConversationRecord(string $conversationId, ?string $content, string $kind, ?string $role): void
    {
        $title = $kind === 'message' && $role === 'user' ? $this->buildPreview($content, 80) : null;
        $preview = $kind === 'message' ? $this->buildPreview($content, 120) : null;

        $stmt = $this->db->prepare("UPDATE admin_ai_conversations
            SET
                title = COALESCE(title, :title),
                last_message_preview = COALESCE(:last_message_preview, last_message_preview),
                last_activity_at = CURRENT_TIMESTAMP
            WHERE conversation_id = :conversation_id");
        $stmt->execute([
            ':title' => $title,
            ':last_message_preview' => $preview,
            ':conversation_id' => $conversationId,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchStoredConversationTimeline(string $conversationId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, kind, role, action, status, content, request_id, response_code, meta_json, created_at
                FROM admin_ai_messages
                WHERE conversation_id = :conversation_id
                ORDER BY created_at ASC, id ASC");
            $stmt->execute([':conversation_id' => $conversationId]);

            $messages = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $meta = $this->decodeJson($row['meta_json'] ?? null);
                $data = isset($meta['data']) && is_array($meta['data']) ? $meta['data'] : [];
                $proposal = null;
                if (($row['kind'] ?? null) === 'action_proposed') {
                    $proposal = [
                        'proposal_id' => (int) ($row['id'] ?? 0),
                        'action_name' => $data['action_name'] ?? null,
                        'label' => $data['label'] ?? null,
                        'summary' => $data['summary'] ?? ($row['content'] ?? null),
                        'payload' => $data['payload'] ?? null,
                        'risk_level' => $data['risk_level'] ?? null,
                        'status' => $row['status'] ?? null,
                    ];
                }

                $messages[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'kind' => $row['kind'] ?? 'event',
                    'role' => $row['role'] ?? 'assistant',
                    'action' => $row['action'] ?? null,
                    'status' => $row['status'] ?? null,
                    'content' => $row['content'] ?? null,
                    'proposal' => $proposal,
                    'meta' => [
                        'request_id' => $meta['request_id'] ?? ($row['request_id'] ?? null),
                        'response_code' => $meta['response_code'] ?? ($row['response_code'] !== null ? (int) $row['response_code'] : null),
                        'data' => $data,
                    ],
                    'created_at' => $row['created_at'] ?? null,
                ];
            }

            return $messages;
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to fetch admin AI conversation timeline from dedicated store.', [
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    private function mapConversationActionToKind(string $action): string
    {
        return match (true) {
            $action === 'admin_ai_user_message', $action === 'admin_ai_assistant_message' => 'message',
            $action === 'admin_ai_action_proposed' => 'action_proposed',
            $action === 'admin_ai_tool_invocation' => 'tool',
            str_starts_with($action, 'admin_ai_action_') => 'action_event',
            default => 'event',
        };
    }

    private function mapConversationActionToRole(string $action): ?string
    {
        return match ($action) {
            'admin_ai_user_message' => 'user',
            'admin_ai_assistant_message' => 'assistant',
            default => null,
        };
    }

    private function normalizeConversationId(?string $conversationId): ?string
    {
        if (!is_string($conversationId)) {
            return null;
        }

        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]{6,128}$/', $conversationId) === 1 ? $conversationId : null;
    }

    private function normalizeDateBoundary(mixed $value, bool $endOfDay): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function buildPreview(?string $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') <= $maxLength) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $maxLength, 'UTF-8') . '...';
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
