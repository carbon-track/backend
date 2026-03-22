<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Services\Ai\LlmClientInterface;
use CarbonTrack\Support\SyntheticRequestFactory;
use PDO;
use Psr\Log\LoggerInterface;

class AdminAiAgentService
{
    private const ALLOWED_CONTEXT_KEYS = [
        'activeRoute',
        'selectedRecordIds',
        'selectedUserId',
        'locale',
        'timezone',
    ];

    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $enabled;

    /** @var array<string,mixed> */
    private array $agentConfig = [];

    /** @var array<string,array<string,mixed>> */
    private array $navigationTargets = [];

    /** @var array<string,array<string,mixed>> */
    private array $quickActions = [];

    /** @var array<string,array<string,mixed>> */
    private array $actionDefinitions = [];

    public function __construct(
        private PDO $db,
        private ?LlmClientInterface $client,
        private LoggerInterface $logger,
        array $config = [],
        ?array $commandConfig = null,
        private ?LlmLogService $llmLogService = null,
        private ?AuditLogService $auditLogService = null,
        private ?ErrorLogService $errorLogService = null,
        private ?StatisticsService $statisticsService = null,
        private ?MessageService $messageService = null,
        private ?BadgeService $badgeService = null
    ) {
        $this->model = (string) ($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.2;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 900;
        $this->enabled = $client !== null;
        $this->loadCommandConfig($commandConfig ?? []);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $decision
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    public function chat(
        ?string $conversationId,
        ?string $message,
        array $context = [],
        ?array $decision = null,
        array $logContext = []
    ): array {
        if (!$this->enabled) {
            throw new \RuntimeException('AI agent service is disabled');
        }

        $normalizedContext = $this->normalizeContext($context);
        $normalizedMessage = trim((string) ($message ?? ''));
        $conversationId = $this->normalizeConversationId($conversationId) ?? $this->generateConversationId();

        if ($normalizedMessage === '' && $decision === null) {
            throw new \InvalidArgumentException('Either message or decision is required.');
        }

        if ($decision !== null) {
            return $this->handleDecision($conversationId, $decision, $normalizedContext, $logContext);
        }

        $this->logConversationEvent('admin_ai_user_message', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $normalizedMessage,
            'role' => 'user',
            'context' => $normalizedContext,
        ]);

        $turnNo = $this->getNextTurnNo($conversationId);
        $history = $this->fetchHistoryMessages($conversationId);
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->buildMessages($history, $normalizedMessage, $normalizedContext),
            'tools' => $this->buildTools(),
            'tool_choice' => 'auto',
        ];

        $startedAt = microtime(true);
        try {
            $rawResponse = $this->client->createChatCompletion($payload);
            $this->logLlmCall($payload['messages'], $rawResponse, $logContext, $normalizedContext, $conversationId, $turnNo, $startedAt);
        } catch (\Throwable $exception) {
            $this->logLlmFailure($payload['messages'], $logContext, $normalizedContext, $conversationId, $turnNo, $startedAt, $exception);
            $this->logError($exception, $logContext, [
                'conversation_id' => $conversationId,
                'message' => $normalizedMessage,
                'context' => $normalizedContext,
            ]);
            throw new \RuntimeException('LLM_UNAVAILABLE', 0, $exception);
        }

        $outcome = $this->processModelResponse($conversationId, $normalizedContext, $logContext, $rawResponse);

        if (($outcome['assistant_text'] ?? '') !== '') {
            $this->logConversationEvent('admin_ai_assistant_message', $logContext, [
                'conversation_id' => $conversationId,
                'visible_text' => $outcome['assistant_text'],
                'role' => 'assistant',
                'meta' => $outcome['meta'] ?? null,
                'suggestion' => $outcome['suggestion'] ?? null,
                'proposal' => $outcome['proposal'] ?? null,
                'result' => $outcome['result'] ?? null,
            ]);
        }

        return [
            'success' => true,
            'conversation_id' => $conversationId,
            'message' => $outcome['assistant_text'] ?? '',
            'metadata' => array_merge($outcome['metadata'] ?? [], [
                'timestamp' => gmdate(DATE_ATOM),
            ]),
            'conversation' => $this->getConversationDetail($conversationId),
        ];
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
                    base.conversation_id,
                    base.started_at,
                    base.last_activity_at,
                    base.admin_id,
                    COALESCE(pending.pending_action_count, 0) AS pending_action_count,
                    COALESCE(llm.llm_calls, 0) AS llm_calls,
                    COALESCE(llm.total_tokens, 0) AS total_tokens,
                    llm.last_model
                FROM (
                    SELECT
                        conversation_id,
                        MIN(created_at) AS started_at,
                        MAX(created_at) AS last_activity_at,
                        MAX(user_id) AS admin_id
                    FROM audit_logs
                    WHERE actor_type = 'admin'
                      AND operation_category = 'admin_ai'
                      AND conversation_id IS NOT NULL
                      AND conversation_id <> ''";
        /** @var array<string,array{0:mixed,1:int}> $params */
        $params = [];
        if ($actorId !== null) {
            $sql .= " AND user_id = :actor_id";
            $params[':actor_id'] = [$actorId, PDO::PARAM_INT];
        }
        if ($conversationIdFilter !== null) {
            $sql .= " AND conversation_id = :conversation_id";
            $params[':conversation_id'] = [$conversationIdFilter, PDO::PARAM_STR];
        }
        $sql .= " GROUP BY conversation_id
                ) base
                LEFT JOIN (
                    SELECT conversation_id, COUNT(*) AS pending_action_count
                    FROM audit_logs
                    WHERE action = 'admin_ai_action_proposed'
                      AND status = 'pending'
                      AND conversation_id IS NOT NULL
                      AND conversation_id <> ''
                    GROUP BY conversation_id
                ) pending ON pending.conversation_id = base.conversation_id
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
                ) llm ON llm.conversation_id = base.conversation_id
                WHERE 1 = 1";

        if ($dateFrom !== null) {
            $sql .= " AND base.last_activity_at >= :date_from";
            $params[':date_from'] = [$dateFrom, PDO::PARAM_STR];
        }
        if ($dateTo !== null) {
            $sql .= " AND base.last_activity_at <= :date_to";
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

        $sql .= " ORDER BY base.last_activity_at DESC LIMIT :limit";

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
            $preview = $this->fetchConversationPreview($conversationId);
            $pendingCount = (int) ($row['pending_action_count'] ?? 0);
            $items[] = [
                'conversation_id' => $conversationId,
                'started_at' => $row['started_at'] ?? null,
                'last_activity_at' => $row['last_activity_at'] ?? null,
                'admin_id' => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
                'message_count' => (int) ($preview['message_count'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'llm_calls' => (int) ($row['llm_calls'] ?? 0),
                'last_model' => $row['last_model'] ?? null,
                'status' => $pendingCount > 0 ? 'waiting_confirmation' : 'active',
                'pending_action_count' => $pendingCount,
                'title' => $preview['title'] ?? null,
                'last_message_preview' => $preview['last_message_preview'] ?? null,
            ];
        }

        return $items;
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

    private function loadCommandConfig(array $commandConfig): void
    {
        $defaults = self::defaultCommandConfig();
        $provided = $commandConfig;

        $this->navigationTargets = $this->indexById($provided['navigationTargets'] ?? $defaults['navigationTargets']);
        $this->quickActions = $this->indexById($provided['quickActions'] ?? $defaults['quickActions']);
        $this->actionDefinitions = $this->indexById($provided['managementActions'] ?? $defaults['managementActions'], 'name');
        $this->agentConfig = is_array($provided['agent'] ?? null) ? $provided['agent'] : ($defaults['agent'] ?? []);
    }

    private function indexById(array $items, string $key = 'id'): array
    {
        $indexed = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $identifier = $item[$key] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                continue;
            }
            $indexed[$identifier] = $item;
        }

        return $indexed;
    }

    private static function defaultCommandConfig(): array
    {
        return [
            'agent' => [
                'max_history_messages' => 12,
                'default_confirmation_policy' => 'write_requires_confirmation',
            ],
            'navigationTargets' => [],
            'quickActions' => [],
            'managementActions' => [],
        ];
    }

    private function buildSystemPrompt(): string
    {
        $lines = [
            'You are the CarbonTrack admin AI assistant.',
            'Operate as a multi-turn administrative agent.',
            'Use tools whenever navigation, data lookup, or execution is required.',
            'Never claim a write action has executed before explicit confirmation.',
            'If required fields are missing, ask a concise follow-up question.',
            'Keep answers concise and operational.',
        ];

        if ($this->actionDefinitions !== []) {
            $lines[] = 'Available management actions:';
            foreach ($this->actionDefinitions as $name => $definition) {
                $lines[] = sprintf(
                    '- %s: %s [risk=%s, confirm=%s]',
                    $name,
                    (string) ($definition['description'] ?? $definition['label'] ?? $name),
                    (string) ($definition['risk_level'] ?? 'read'),
                    !empty($definition['requires_confirmation']) ? 'yes' : 'no'
                );
            }
        }

        return implode("\n", $lines);
    }

    private function buildMessages(array $history, string $message, array $context): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $this->buildSystemPrompt(),
        ]];

        foreach ($history as $entry) {
            $messages[] = [
                'role' => $entry['role'],
                'content' => $entry['content'],
            ];
        }

        if ($context !== []) {
            $messages[] = [
                'role' => 'system',
                'content' => 'Current admin UI context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    private function buildTools(): array
    {
        $tools = [];

        if ($this->navigationTargets !== []) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'navigate',
                    'description' => 'Suggest a navigation target in the admin UI.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'destination' => [
                                'type' => 'string',
                                'enum' => array_keys($this->navigationTargets),
                            ],
                            'parameters' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                        ],
                        'required' => ['destination'],
                    ],
                ],
            ];
        }

        if ($this->quickActions !== []) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'execute_shortcut',
                    'description' => 'Suggest a quick action in the admin UI.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shortcut_id' => [
                                'type' => 'string',
                                'enum' => array_keys($this->quickActions),
                            ],
                        ],
                        'required' => ['shortcut_id'],
                    ],
                ],
            ];
        }

        if ($this->actionDefinitions !== []) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'manage_admin',
                    'description' => 'Run a read-only admin query or prepare a write action proposal.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => [
                                'type' => 'string',
                                'enum' => array_keys($this->actionDefinitions),
                            ],
                            'payload' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                        ],
                        'required' => ['action'],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private function processModelResponse(string $conversationId, array $context, array $logContext, array $rawResponse): array
    {
        $choice = $rawResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];
        $content = isset($message['content']) ? trim((string) $message['content']) : '';

        if ($toolCalls === []) {
            return [
                'assistant_text' => $content !== '' ? $content : '我暂时无法完成这项操作，请再具体一些。',
                'metadata' => $this->extractMetadata($rawResponse),
            ];
        }

        $toolCall = $toolCalls[0];
        $functionName = (string) ($toolCall['function']['name'] ?? '');
        $arguments = json_decode((string) ($toolCall['function']['arguments'] ?? '{}'), true);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        return match ($functionName) {
            'navigate' => $this->handleNavigationTool($arguments, $rawResponse),
            'execute_shortcut' => $this->handleShortcutTool($arguments, $rawResponse),
            'manage_admin' => $this->handleManageAdminTool($conversationId, $arguments, $context, $logContext, $rawResponse),
            default => [
                'assistant_text' => '我没有找到可执行的管理员工具，请换个说法再试一次。',
                'metadata' => $this->extractMetadata($rawResponse),
            ],
        };
    }

    private function handleNavigationTool(array $arguments, array $rawResponse): array
    {
        $destination = $arguments['destination'] ?? null;
        if (!is_string($destination) || !isset($this->navigationTargets[$destination])) {
            return [
                'assistant_text' => '我暂时无法定位到对应的后台页面。',
                'metadata' => $this->extractMetadata($rawResponse),
            ];
        }

        $target = $this->navigationTargets[$destination];
        $query = isset($arguments['parameters']) && is_array($arguments['parameters']) ? $arguments['parameters'] : [];

        return [
            'assistant_text' => sprintf('建议前往“%s”继续处理。', (string) ($target['label'] ?? $destination)),
            'suggestion' => [
                'type' => 'navigate',
                'label' => $target['label'] ?? $destination,
                'route' => $target['route'] ?? null,
                'query' => $query,
            ],
            'metadata' => $this->extractMetadata($rawResponse),
        ];
    }

    private function handleShortcutTool(array $arguments, array $rawResponse): array
    {
        $shortcutId = $arguments['shortcut_id'] ?? null;
        if (!is_string($shortcutId) || !isset($this->quickActions[$shortcutId])) {
            return [
                'assistant_text' => '我暂时无法定位到对应的快捷操作。',
                'metadata' => $this->extractMetadata($rawResponse),
            ];
        }

        $target = $this->quickActions[$shortcutId];
        return [
            'assistant_text' => sprintf('建议直接使用快捷操作“%s”。', (string) ($target['label'] ?? $shortcutId)),
            'suggestion' => [
                'type' => 'quick_action',
                'label' => $target['label'] ?? $shortcutId,
                'route' => $target['route'] ?? null,
                'query' => $target['query'] ?? [],
            ],
            'metadata' => $this->extractMetadata($rawResponse),
        ];
    }

    private function handleManageAdminTool(
        string $conversationId,
        array $arguments,
        array $context,
        array $logContext,
        array $rawResponse
    ): array {
        $actionName = $arguments['action'] ?? null;
        if (!is_string($actionName) || !isset($this->actionDefinitions[$actionName])) {
            return [
                'assistant_text' => '我暂时无法执行这个后台动作。',
                'metadata' => $this->extractMetadata($rawResponse),
            ];
        }

        $definition = $this->actionDefinitions[$actionName];
        $payload = isset($arguments['payload']) && is_array($arguments['payload']) ? $arguments['payload'] : [];
        $payload = $this->applyPayloadTemplate($definition, $payload, $context);
        $missing = $this->resolveMissingRequirements((array) ($definition['requires'] ?? []), $payload);

        if ($missing !== []) {
            return [
                'assistant_text' => '还缺少必要信息：' . implode('、', array_map(static fn (array $item) => (string) $item['field'], $missing)) . '。',
                'metadata' => $this->extractMetadata($rawResponse),
                'meta' => ['missing' => $missing],
            ];
        }

        $persistedPayload = $this->preparePersistedActionPayload($actionName, $payload);

        $this->logConversationEvent('admin_ai_tool_invocation', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => sprintf('调用工具：%s', $actionName),
            'tool_name' => $actionName,
            'request_data' => $persistedPayload,
        ]);

        $isReadAction = ($definition['risk_level'] ?? 'read') === 'read' && empty($definition['requires_confirmation']);
        if ($isReadAction) {
            $result = $this->executeReadAction($actionName, $payload);
            return [
                'assistant_text' => $this->formatReadActionResult($actionName, $result),
                'result' => $result,
                'metadata' => $this->extractMetadata($rawResponse),
                'meta' => ['action_name' => $actionName],
            ];
        }

        $summary = $this->buildProposalSummary($definition, $payload);
        $proposalData = [
            'conversation_id' => $conversationId,
            'action_name' => $actionName,
            'label' => $definition['label'] ?? $actionName,
            'summary' => $summary,
            'payload' => $persistedPayload,
            'risk_level' => $definition['risk_level'] ?? 'write',
            'requires_confirmation' => true,
        ];

        $this->logConversationEvent('admin_ai_action_proposed', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $summary,
            'request_data' => $proposalData,
            'status' => 'pending',
        ]);
        $proposalId = $this->auditLogService?->getLastInsertId();

        return [
            'assistant_text' => sprintf("已整理待执行操作：%s\n如需执行，请确认。", $summary),
            'proposal' => array_merge($proposalData, [
                'proposal_id' => $proposalId,
                'status' => 'pending',
            ]),
            'metadata' => $this->extractMetadata($rawResponse),
            'meta' => ['action_name' => $actionName, 'proposal_id' => $proposalId],
        ];
    }

    private function handleDecision(string $conversationId, array $decision, array $context, array $logContext): array
    {
        $proposalId = isset($decision['proposal_id']) && is_numeric((string) $decision['proposal_id'])
            ? (int) $decision['proposal_id']
            : 0;
        $outcome = strtolower(trim((string) ($decision['outcome'] ?? '')));

        if ($proposalId <= 0 || !in_array($outcome, ['confirm', 'reject'], true)) {
            throw new \InvalidArgumentException('Invalid decision payload.');
        }

        $proposal = $this->findProposal($conversationId, $proposalId);
        if ($proposal === null) {
            throw new \RuntimeException('PROPOSAL_NOT_FOUND');
        }

        $actionName = (string) ($proposal['action_name'] ?? '');
        $payload = is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [];

        if ($outcome === 'reject') {
            $this->updateProposalStatus($proposalId, 'failed', ['decision' => 'rejected']);
            $this->logConversationEvent('admin_ai_action_rejected', $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
                'request_data' => $payload,
            ]);
            $assistantText = '已取消该待执行操作。你可以补充条件后重新下达指令。';
            $this->logConversationEvent('admin_ai_assistant_message', $logContext, [
                'conversation_id' => $conversationId,
                'visible_text' => $assistantText,
                'role' => 'assistant',
                'meta' => ['decision' => 'rejected', 'proposal_id' => $proposalId],
            ]);
            return [
                'success' => true,
                'conversation_id' => $conversationId,
                'message' => $assistantText,
                'metadata' => ['decision' => 'rejected', 'timestamp' => gmdate(DATE_ATOM)],
                'conversation' => $this->getConversationDetail($conversationId),
            ];
        }

        $this->logConversationEvent('admin_ai_action_confirmed', $logContext, [
            'conversation_id' => $conversationId,
            'proposal_id' => $proposalId,
            'action_name' => $actionName,
            'request_data' => $payload,
        ]);

        try {
            $executeLogContext = $logContext;
            $executeLogContext['conversation_id'] = $conversationId;
            $result = $this->executeWriteAction($actionName, $payload, $executeLogContext);
            $this->updateProposalStatus($proposalId, 'success', ['decision' => 'confirmed', 'result' => $result]);
            $this->logConversationEvent('admin_ai_action_executed', $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
                'request_data' => $payload,
                'new_data' => $result,
            ]);
            $assistantText = $this->formatWriteActionResult($actionName, $result);
            $meta = ['decision' => 'confirmed', 'proposal_id' => $proposalId, 'result' => $result];
        } catch (\Throwable $exception) {
            $this->updateProposalStatus($proposalId, 'failed', ['decision' => 'confirmed', 'error' => $exception->getMessage()]);
            $this->logConversationEvent('admin_ai_action_failed', $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
                'request_data' => $payload,
                'status' => 'failed',
            ]);
            $this->logError($exception, $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
            ]);
            $assistantText = '执行该操作时出现错误，请稍后重试。';
            $meta = ['decision' => 'confirmed', 'proposal_id' => $proposalId, 'error' => $exception->getMessage()];
        }

        $this->logConversationEvent('admin_ai_assistant_message', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $assistantText,
            'role' => 'assistant',
            'meta' => $meta,
        ]);

        return [
            'success' => true,
            'conversation_id' => $conversationId,
            'message' => $assistantText,
            'metadata' => array_merge($meta, ['timestamp' => gmdate(DATE_ATOM)]),
            'conversation' => $this->getConversationDetail($conversationId),
        ];
    }

    private function applyPayloadTemplate(array $definition, array $payload, array $context): array
    {
        $template = isset($definition['api']['payloadTemplate']) && is_array($definition['api']['payloadTemplate'])
            ? $definition['api']['payloadTemplate']
            : [];
        $finalPayload = array_merge($template, $payload);

        $contextHints = isset($definition['contextHints']) && is_array($definition['contextHints'])
            ? $definition['contextHints']
            : [];
        if (in_array('selectedRecordIds', $contextHints, true) && empty($finalPayload['record_ids']) && !empty($context['selectedRecordIds'])) {
            $finalPayload['record_ids'] = array_values((array) $context['selectedRecordIds']);
        }
        if (
            in_array('selectedUserId', $contextHints, true)
            && empty($finalPayload['user_id'])
            && !empty($context['selectedUserId'])
            && is_numeric((string) $context['selectedUserId'])
        ) {
            $finalPayload['user_id'] = (int) $context['selectedUserId'];
        }
        if (isset($finalPayload['days']) && is_numeric($finalPayload['days'])) {
            $finalPayload['days'] = max(7, min(90, (int) $finalPayload['days']));
        }
        if (isset($finalPayload['limit']) && is_numeric($finalPayload['limit'])) {
            $finalPayload['limit'] = max(1, min(50, (int) $finalPayload['limit']));
        }

        return $finalPayload;
    }

    private function resolveMissingRequirements(array $requirements, array $payload): array
    {
        $missing = [];
        foreach ($requirements as $field) {
            if (is_array($field) && isset($field['anyOf']) && is_array($field['anyOf'])) {
                $hasValue = false;
                foreach ($field['anyOf'] as $candidate) {
                    if (!is_string($candidate) || $candidate === '') {
                        continue;
                    }
                    $value = $payload[$candidate] ?? null;
                    $hasCandidateValue = is_array($value)
                        ? count(array_filter($value, static fn ($item) => $item !== null && $item !== '' && $item !== [])) > 0
                        : ($value !== null && $value !== '');
                    if ($hasCandidateValue) {
                        $hasValue = true;
                        break;
                    }
                }

                if (!$hasValue) {
                    $missing[] = ['field' => (string) ($field['label'] ?? implode('_or_', $field['anyOf']))];
                }
                continue;
            }

            if (!is_string($field) || $field === '') {
                continue;
            }

            $value = $payload[$field] ?? null;
            $isMissing = is_array($value)
                ? count(array_filter($value, static fn ($item) => $item !== null && $item !== '' && $item !== [])) === 0
                : ($value === null || $value === '');

            if ($isMissing) {
                $missing[] = ['field' => $field];
            }
        }

        return $missing;
    }

    private function executeReadAction(string $actionName, array $payload): array
    {
        return match ($actionName) {
            'get_admin_stats' => [
                'scope' => 'admin_stats',
                'data' => $this->statisticsService?->getAdminStats(false) ?? [],
            ],
            'get_pending_carbon_records' => $this->queryPendingCarbonRecords($payload),
            'get_llm_usage_analytics' => $this->queryLlmUsageAnalytics((int) ($payload['days'] ?? 30)),
            'get_activity_statistics' => $this->queryActivityStatistics($payload),
            'generate_admin_report' => $this->buildAdminReport((int) ($payload['days'] ?? 30)),
            'search_users' => $this->queryUsers($payload),
            'get_user_overview' => $this->queryUserOverview($payload),
            'get_exchange_orders' => $this->queryExchangeOrders($payload),
            'get_exchange_order_detail' => $this->queryExchangeOrderDetail($payload),
            'get_product_catalog' => $this->queryProductCatalog($payload),
            'get_passkey_admin_stats' => $this->queryPasskeyAdminStats(),
            'get_passkey_admin_list' => $this->queryPasskeyAdminList($payload),
            'search_system_logs' => $this->querySystemLogs($payload),
            'get_broadcast_history' => $this->queryBroadcastHistory($payload),
            'search_broadcast_recipients' => $this->queryBroadcastRecipients($payload),
            default => throw new \RuntimeException('Unsupported read action: ' . $actionName),
        };
    }

    private function executeWriteAction(string $actionName, array $payload, array $logContext): array
    {
        return match ($actionName) {
            'approve_carbon_records' => $this->reviewCarbonRecords('approve', $payload, $logContext),
            'reject_carbon_records' => $this->reviewCarbonRecords('reject', $payload, $logContext),
            'adjust_user_points' => $this->adjustUserPoints($payload, $logContext),
            'create_user' => $this->createUserAccount($payload, $logContext),
            'update_user_status' => $this->updateUserStatus($payload, $logContext),
            'award_badge_to_user' => $this->awardBadgeToUser($payload, $logContext),
            'revoke_badge_from_user' => $this->revokeBadgeFromUser($payload, $logContext),
            'update_exchange_status' => $this->updateExchangeStatus($payload, $logContext),
            'update_product_status' => $this->updateProductStatus($payload, $logContext),
            'adjust_product_inventory' => $this->adjustProductInventory($payload, $logContext),
            default => throw new \RuntimeException('Unsupported write action: ' . $actionName),
        };
    }

    private function queryPendingCarbonRecords(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 5)));
        $status = trim((string) ($payload['status'] ?? 'pending'));
        $where = ['r.deleted_at IS NULL', 'r.status = :status'];
        $params = [':status' => $status];

        if (!empty($payload['record_ids']) && is_array($payload['record_ids'])) {
            $placeholders = [];
            foreach (array_values($payload['record_ids']) as $index => $id) {
                $placeholder = ':record_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (string) $id;
            }
            if ($placeholders !== []) {
                $where[] = 'r.id IN (' . implode(',', $placeholders) . ')';
            }
        }

        $sql = "SELECT r.id, r.status, r.date, r.carbon_saved, r.points_earned, u.username, u.email,
                       a.name_zh AS activity_name_zh, a.name_en AS activity_name_en
                FROM carbon_records r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.created_at DESC
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => $row['id'],
                'status' => $row['status'],
                'date' => $row['date'],
                'carbon_saved' => $row['carbon_saved'] !== null ? (float) $row['carbon_saved'] : null,
                'points_earned' => $row['points_earned'] !== null ? (int) $row['points_earned'] : null,
                'username' => $row['username'],
                'email' => $row['email'],
                'activity_name' => $row['activity_name_zh'] ?: ($row['activity_name_en'] ?: null),
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM carbon_records r WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'pending_carbon_records',
            'status' => $status,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    private function queryLlmUsageAnalytics(int $days): array
    {
        $days = max(7, min(90, $days));
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(0, $days - 1) . ' days')
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');

        $summaryStmt = $this->db->prepare("SELECT COUNT(*) AS total_calls, COALESCE(SUM(total_tokens), 0) AS total_tokens,
                AVG(latency_ms) AS avg_latency_ms, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_calls
            FROM llm_logs WHERE created_at >= :since");
        $summaryStmt->execute([':since' => $since]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $topModelStmt = $this->db->prepare("SELECT model, COUNT(*) AS calls FROM llm_logs WHERE created_at >= :since GROUP BY model ORDER BY calls DESC LIMIT 1");
        $topModelStmt->execute([':since' => $since]);
        $topModel = $topModelStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $topSourceStmt = $this->db->prepare("SELECT source, COUNT(*) AS calls FROM llm_logs WHERE created_at >= :since GROUP BY source ORDER BY calls DESC LIMIT 1");
        $topSourceStmt->execute([':since' => $since]);
        $topSource = $topSourceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'scope' => 'llm_usage_analytics',
            'days' => $days,
            'total_calls' => (int) ($summary['total_calls'] ?? 0),
            'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
            'avg_latency_ms' => isset($summary['avg_latency_ms']) ? round((float) $summary['avg_latency_ms'], 2) : null,
            'success_calls' => (int) ($summary['success_calls'] ?? 0),
            'top_model' => $topModel['model'] ?? null,
            'top_source' => $topSource['source'] ?? null,
        ];
    }

    private function queryActivityStatistics(array $payload): array
    {
        $activityId = trim((string) ($payload['activity_id'] ?? ''));
        $where = ['r.deleted_at IS NULL'];
        $params = [];
        if ($activityId !== '') {
            $where[] = 'r.activity_id = :activity_id';
            $params[':activity_id'] = $activityId;
        }

        $sql = "SELECT r.activity_id, a.name_zh AS activity_name_zh, a.name_en AS activity_name_en,
                       COUNT(*) AS record_count,
                       SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                       SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                       COALESCE(SUM(CASE WHEN r.status = 'approved' THEN r.carbon_saved ELSE 0 END), 0) AS approved_carbon_saved
                FROM carbon_records r
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY r.activity_id, a.name_zh, a.name_en
                ORDER BY approved_carbon_saved DESC
                LIMIT 10";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name_zh'] ?: ($row['activity_name_en'] ?: null),
                'record_count' => (int) ($row['record_count'] ?? 0),
                'approved_count' => (int) ($row['approved_count'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
                'approved_carbon_saved' => (float) ($row['approved_carbon_saved'] ?? 0),
            ];
        }

        return [
            'scope' => 'activity_statistics',
            'activity_id' => $activityId !== '' ? $activityId : null,
            'items' => $items,
        ];
    }

    private function buildAdminReport(int $days): array
    {
        return [
            'scope' => 'admin_report',
            'days' => $days,
            'stats' => $this->statisticsService?->getAdminStats(false) ?? [],
            'llm' => $this->queryLlmUsageAnalytics($days),
            'pending' => $this->queryPendingCarbonRecords(['status' => 'pending', 'limit' => 5]),
        ];
    }

    private function queryUsers(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? $payload['keyword'] ?? $payload['query'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $userUuid = strtolower(trim((string) ($payload['user_uuid'] ?? '')));
        $schoolId = isset($payload['school_id']) && is_numeric((string) $payload['school_id']) ? (int) $payload['school_id'] : null;
        $role = strtolower(trim((string) ($payload['role'] ?? '')));

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE :search OR u.email LIKE :search OR u.uuid LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }
        if ($userUuid !== '') {
            $where[] = 'LOWER(u.uuid) = :user_uuid';
            $params[':user_uuid'] = $userUuid;
        }
        if ($schoolId !== null && $schoolId > 0) {
            $where[] = 'u.school_id = :school_id';
            $params[':school_id'] = $schoolId;
        }
        if ($role === 'admin') {
            $where[] = 'u.is_admin = 1';
        } elseif ($role === 'user') {
            $where[] = 'u.is_admin = 0';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'username_asc' => 'u.username ASC, u.id ASC',
            'username_desc' => 'u.username DESC, u.id DESC',
            'points_asc' => 'u.points ASC, u.id ASC',
            'points_desc' => 'u.points DESC, u.id DESC',
            'created_at_asc' => 'u.created_at ASC, u.id ASC',
            default => 'u.created_at DESC, u.id DESC',
        };

        $sql = "SELECT u.id, u.uuid, u.username, u.email, u.status, u.points, u.is_admin, u.created_at,
                       s.name AS school_name, COALESCE(pk.passkey_count, 0) AS passkey_count
                FROM users u
                LEFT JOIN schools s ON s.id = u.school_id
                LEFT JOIN (
                    SELECT user_uuid, COUNT(*) AS passkey_count
                    FROM user_passkeys
                    WHERE disabled_at IS NULL
                    GROUP BY user_uuid
                ) pk ON LOWER(pk.user_uuid) = LOWER(u.uuid)
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'uuid' => $row['uuid'] ?? null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $row['status'] ?? null,
                'points' => isset($row['points']) ? (int) $row['points'] : 0,
                'is_admin' => !empty($row['is_admin']),
                'school_name' => $row['school_name'] ?? null,
                'passkey_count' => isset($row['passkey_count']) ? (int) $row['passkey_count'] : 0,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'users',
            'search' => $search !== '' ? $search : null,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    private function queryUserOverview(array $payload): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $userId = (int) ($user['id'] ?? 0);
        $userUuid = strtolower((string) ($user['uuid'] ?? ''));

        $carbonStmt = $this->db->prepare("SELECT
                COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_saved ELSE 0 END), 0) AS total_carbon_saved,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_records
            FROM carbon_records
            WHERE user_id = :user_id
              AND deleted_at IS NULL");
        $carbonStmt->execute([':user_id' => $userId]);
        $carbon = $carbonStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $checkinStmt = $this->db->prepare("SELECT COUNT(*) AS checkin_days, MAX(checkin_date) AS last_checkin_date
            FROM user_checkins WHERE user_id = :user_id");
        $checkinStmt->execute([':user_id' => $userId]);
        $checkins = $checkinStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $badgeStmt = $this->db->prepare("SELECT COUNT(*) AS badge_count FROM user_badges WHERE user_id = :user_id");
        $badgeStmt->execute([':user_id' => $userId]);
        $badgeCount = (int) $badgeStmt->fetchColumn();

        $passkeyStmt = $this->db->prepare("SELECT COUNT(*) AS passkey_count, MAX(last_used_at) AS last_used_at
            FROM user_passkeys
            WHERE disabled_at IS NULL
              AND LOWER(user_uuid) = :user_uuid");
        $passkeyStmt->execute([':user_uuid' => $userUuid]);
        $passkeys = $passkeyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'user_overview',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
                'status' => $user['status'] ?? null,
                'points' => isset($user['points']) ? (int) $user['points'] : 0,
                'is_admin' => !empty($user['is_admin']),
                'school_name' => $user['school_name'] ?? null,
                'group_name' => $user['group_name'] ?? null,
                'created_at' => $user['created_at'] ?? null,
                'last_login_at' => $user['lastlgn'] ?? null,
            ],
            'metrics' => [
                'total_carbon_saved' => isset($carbon['total_carbon_saved']) ? (float) $carbon['total_carbon_saved'] : 0.0,
                'approved_records' => (int) ($carbon['approved_records'] ?? 0),
                'pending_records' => (int) ($carbon['pending_records'] ?? 0),
                'checkin_days' => (int) ($checkins['checkin_days'] ?? 0),
                'last_checkin_date' => $checkins['last_checkin_date'] ?? null,
                'badge_count' => $badgeCount,
                'passkey_count' => (int) ($passkeys['passkey_count'] ?? 0),
                'last_passkey_used_at' => $passkeys['last_used_at'] ?? null,
            ],
        ];
    }

    private function queryExchangeOrders(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;
        $userColumn = $this->resolvePointExchangeUserColumn();

        $where = ['e.deleted_at IS NULL'];
        $params = [];
        if ($status !== '') {
            $where[] = 'LOWER(e.status) = :status';
            $params[':status'] = $status;
        }
        if ($userId !== null && $userId > 0) {
            $where[] = "e.{$userColumn} = :user_id";
            $params[':user_id'] = $userId;
        }
        if ($search !== '') {
            $where[] = '(LOWER(e.id) LIKE :search OR LOWER(COALESCE(e.product_name, \'\')) LIKE :search OR LOWER(COALESCE(e.tracking_number, \'\')) LIKE :search OR LOWER(COALESCE(u.username, \'\')) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'created_at_asc' => 'e.created_at ASC, e.id ASC',
            'status_asc' => 'e.status ASC, e.created_at DESC',
            'points_desc' => 'e.points_used DESC, e.created_at DESC',
            default => 'e.created_at DESC, e.id DESC',
        };

        $sql = "SELECT e.id, e.status, e.product_name, e.quantity, e.points_used, e.tracking_number, e.created_at,
                       e.updated_at, e.notes, e.{$userColumn} AS exchange_user_id, u.username, u.email
                FROM point_exchanges e
                LEFT JOIN users u ON u.id = e.{$userColumn}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => $row['id'] ?? null,
                'status' => $row['status'] ?? null,
                'product_name' => $row['product_name'] ?? null,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
                'points_used' => isset($row['points_used']) ? (int) $row['points_used'] : null,
                'tracking_number' => $row['tracking_number'] ?? null,
                'user_id' => isset($row['exchange_user_id']) ? (int) $row['exchange_user_id'] : null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*)
            FROM point_exchanges e
            LEFT JOIN users u ON u.id = e.{$userColumn}
            WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'exchange_orders',
            'status' => $status !== '' ? $status : null,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    private function queryExchangeOrderDetail(array $payload): array
    {
        $exchangeId = trim((string) ($payload['exchange_id'] ?? ''));
        if ($exchangeId === '') {
            throw new \RuntimeException('exchange_id is required.');
        }

        $exchange = $this->fetchExchangeRecordById($exchangeId);
        if ($exchange === null) {
            throw new \RuntimeException('Exchange order not found.');
        }

        $userColumn = $this->resolvePointExchangeUserColumn();

        return [
            'scope' => 'exchange_order_detail',
            'exchange' => [
                'id' => $exchange['id'] ?? null,
                'status' => $exchange['status'] ?? null,
                'product_id' => isset($exchange['product_id']) ? (int) $exchange['product_id'] : null,
                'product_name' => $exchange['product_name'] ?? null,
                'quantity' => isset($exchange['quantity']) ? (int) $exchange['quantity'] : null,
                'points_used' => isset($exchange['points_used']) ? (int) $exchange['points_used'] : null,
                'tracking_number' => $exchange['tracking_number'] ?? null,
                'delivery_address' => $exchange['delivery_address'] ?? null,
                'contact_phone' => $exchange['contact_phone'] ?? null,
                'notes' => $exchange['notes'] ?? null,
                'user_id' => isset($exchange[$userColumn]) ? (int) $exchange[$userColumn] : null,
                'username' => $exchange['username'] ?? null,
                'email' => $exchange['email'] ?? null,
                'created_at' => $exchange['created_at'] ?? null,
                'updated_at' => $exchange['updated_at'] ?? null,
            ],
        ];
    }

    private function queryProductCatalog(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $category = trim((string) ($payload['category'] ?? ''));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));

        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if ($status !== '') {
            $where[] = 'LOWER(p.status) = :status';
            $params[':status'] = $status;
        }
        if ($category !== '') {
            $where[] = '(p.category = :category OR p.category_slug = :category_slug)';
            $params[':category'] = $category;
            $params[':category_slug'] = strtolower($category);
        }
        if ($search !== '') {
            $where[] = '(LOWER(p.name) LIKE :search OR LOWER(COALESCE(p.description, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'points_asc' => 'p.points_required ASC, p.id ASC',
            'points_desc' => 'p.points_required DESC, p.id DESC',
            'stock_desc' => 'p.stock DESC, p.id DESC',
            'created_at_asc' => 'p.created_at ASC, p.id ASC',
            default => 'p.created_at DESC, p.id DESC',
        };

        $sql = "SELECT p.id, p.name, p.category, p.category_slug, p.points_required, p.stock, p.status, p.created_at,
                       COALESCE(e.total_exchanged, 0) AS total_exchanged
                FROM products p
                LEFT JOIN (
                    SELECT product_id, COUNT(*) AS total_exchanged
                    FROM point_exchanges
                    WHERE deleted_at IS NULL
                    GROUP BY product_id
                ) e ON e.product_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => $row['name'] ?? null,
                'category' => $row['category'] ?? null,
                'category_slug' => $row['category_slug'] ?? null,
                'points_required' => isset($row['points_required']) ? (int) $row['points_required'] : 0,
                'stock' => isset($row['stock']) ? (int) $row['stock'] : 0,
                'status' => $row['status'] ?? null,
                'total_exchanged' => isset($row['total_exchanged']) ? (int) $row['total_exchanged'] : 0,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'product_catalog',
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    private function queryPasskeyAdminStats(): array
    {
        $statsStmt = $this->db->query("SELECT
                COUNT(*) AS total_passkeys,
                COUNT(DISTINCT user_uuid) AS users_with_passkeys,
                SUM(CASE WHEN backup_eligible = 1 THEN 1 ELSE 0 END) AS backup_eligible_count,
                SUM(CASE WHEN backup_state = 1 THEN 1 ELSE 0 END) AS backup_state_count,
                SUM(CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END) AS never_used_count,
                MAX(last_used_at) AS last_used_at
            FROM user_passkeys
            WHERE disabled_at IS NULL");
        $stats = $statsStmt instanceof \PDOStatement ? ($statsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $recentStmt = $this->db->query("SELECT COUNT(*) FROM user_passkeys
            WHERE disabled_at IS NULL
              AND last_used_at IS NOT NULL
              AND last_used_at >= datetime('now', '-30 day')");

        return [
            'scope' => 'passkey_admin_stats',
            'total_passkeys' => (int) ($stats['total_passkeys'] ?? 0),
            'users_with_passkeys' => (int) ($stats['users_with_passkeys'] ?? 0),
            'backup_eligible_count' => (int) ($stats['backup_eligible_count'] ?? 0),
            'backup_state_count' => (int) ($stats['backup_state_count'] ?? 0),
            'never_used_count' => (int) ($stats['never_used_count'] ?? 0),
            'used_recently_30d' => (int) (($recentStmt instanceof \PDOStatement ? $recentStmt->fetchColumn() : 0) ?: 0),
            'last_used_at' => $stats['last_used_at'] ?? null,
        ];
    }

    private function queryPasskeyAdminList(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;

        $where = ['pk.disabled_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(LOWER(COALESCE(pk.label, \'\')) LIKE :search OR LOWER(COALESCE(u.username, \'\')) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search OR LOWER(COALESCE(pk.user_uuid, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }
        if ($userId !== null && $userId > 0) {
            $where[] = 'u.id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'last_used_at_desc')));
        $orderBy = match ($sort) {
            'created_at_desc' => 'pk.created_at DESC, pk.id DESC',
            'sign_count_desc' => 'pk.sign_count DESC, pk.id DESC',
            default => 'pk.last_used_at DESC, pk.id DESC',
        };

        $sql = "SELECT pk.id, pk.user_uuid, pk.label, pk.sign_count, pk.backup_eligible, pk.backup_state,
                       pk.last_used_at, pk.created_at, u.id AS user_id, u.username, u.email
                FROM user_passkeys pk
                LEFT JOIN users u ON LOWER(u.uuid) = LOWER(pk.user_uuid)
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'user_uuid' => $row['user_uuid'] ?? null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'label' => $row['label'] ?? null,
                'sign_count' => isset($row['sign_count']) ? (int) $row['sign_count'] : 0,
                'backup_eligible' => !empty($row['backup_eligible']),
                'backup_state' => !empty($row['backup_state']),
                'last_used_at' => $row['last_used_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*)
            FROM user_passkeys pk
            LEFT JOIN users u ON LOWER(u.uuid) = LOWER(pk.user_uuid)
            WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'passkey_admin_list',
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    private function querySystemLogs(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['q'] ?? $payload['search'] ?? ''));
        $requestId = trim((string) ($payload['request_id'] ?? ''));
        $conversationId = $this->normalizeConversationId(isset($payload['conversation_id']) ? (string) $payload['conversation_id'] : null);
        $requestedTypes = is_array($payload['types'] ?? null) ? $payload['types'] : ['audit', 'llm', 'error'];
        $allowedTypes = ['audit', 'llm', 'error', 'system'];
        $types = array_values(array_intersect($allowedTypes, array_map(static fn ($item) => strtolower(trim((string) $item)), $requestedTypes)));
        if ($types === []) {
            $types = ['audit', 'llm', 'error'];
        }

        $items = [];
        $searchLike = $search !== '' ? '%' . strtolower($search) . '%' : null;

        if (in_array('audit', $types, true)) {
            $sql = "SELECT id, action, request_id, conversation_id, data, created_at
                FROM audit_logs
                WHERE operation_category = 'admin_ai'";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($conversationId !== null) {
                $sql .= " AND conversation_id = :conversation_id";
                $params[':conversation_id'] = $conversationId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(action) LIKE :search OR LOWER(COALESCE(data, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $data = $this->decodeJson($row['data'] ?? null);
                $items[] = [
                    'type' => 'audit',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'conversation_id' => $row['conversation_id'] ?? null,
                    'summary' => $data['visible_text'] ?? ($row['action'] ?? null),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('llm', $types, true)) {
            $sql = "SELECT id, request_id, conversation_id, turn_no, model, total_tokens, created_at
                FROM llm_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($conversationId !== null) {
                $sql .= " AND conversation_id = :conversation_id";
                $params[':conversation_id'] = $conversationId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(model, '')) LIKE :search OR LOWER(COALESCE(prompt, '')) LIKE :search OR LOWER(COALESCE(response_raw, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'llm',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'conversation_id' => $row['conversation_id'] ?? null,
                    'turn_no' => isset($row['turn_no']) ? (int) $row['turn_no'] : null,
                    'summary' => sprintf('%s / %s tokens', (string) ($row['model'] ?? 'unknown-model'), (string) ($row['total_tokens'] ?? 0)),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('error', $types, true)) {
            $sql = "SELECT id, request_id, error_type, error_message, created_at
                FROM error_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(error_type, '')) LIKE :search OR LOWER(COALESCE(error_message, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'error',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'summary' => trim((string) (($row['error_type'] ?? 'error') . ': ' . ($row['error_message'] ?? ''))),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('system', $types, true)) {
            $sql = "SELECT id, request_id, method, path, status_code, created_at
                FROM system_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(method, '')) LIKE :search OR LOWER(COALESCE(path, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'system',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'summary' => trim((string) (($row['method'] ?? 'GET') . ' ' . ($row['path'] ?? '/') . ' [' . ($row['status_code'] ?? '?') . ']')),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });
        $items = array_slice($items, 0, $limit);

        return [
            'scope' => 'system_logs',
            'returned_count' => count($items),
            'items' => $items,
        ];
    }

    private function queryBroadcastHistory(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $sql = "SELECT id, title, priority, scope, target_count, sent_count, created_by, created_at
            FROM message_broadcasts
            ORDER BY id DESC
            LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'title' => $row['title'] ?? null,
                'priority' => $row['priority'] ?? null,
                'scope' => $row['scope'] ?? null,
                'target_count' => isset($row['target_count']) ? (int) $row['target_count'] : 0,
                'sent_count' => isset($row['sent_count']) ? (int) $row['sent_count'] : 0,
                'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->query("SELECT COUNT(*) FROM message_broadcasts");

        return [
            'scope' => 'broadcast_history',
            'total' => (int) (($countStmt instanceof \PDOStatement ? $countStmt->fetchColumn() : 0) ?: 0),
            'items' => $items,
        ];
    }

    private function queryBroadcastRecipients(array $payload): array
    {
        $users = $this->queryUsers([
            'search' => $payload['search'] ?? $payload['q'] ?? '',
            'status' => $payload['status'] ?? null,
            'limit' => $payload['limit'] ?? 20,
        ]);

        return [
            'scope' => 'broadcast_recipients',
            'total' => $users['total'] ?? 0,
            'items' => $users['items'] ?? [],
        ];
    }

    private function adjustUserPoints(array $payload, array $logContext): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $delta = isset($payload['delta']) && is_numeric((string) $payload['delta']) ? (float) $payload['delta'] : null;
        if ($delta === null || abs($delta) < 0.00001) {
            throw new \RuntimeException('Invalid points delta.');
        }

        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $oldPoints = isset($user['points']) ? (int) $user['points'] : 0;
        $updatedAt = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE users
            SET points = COALESCE(points, 0) + :delta,
                updated_at = :updated_at
            WHERE id = :user_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':delta' => $delta,
            ':updated_at' => $updatedAt,
            ':user_id' => $userId,
        ]);

        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_points_adjusted', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'old_data' => ['points' => $oldPoints],
            'new_data' => ['points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'delta' => $delta,
                'reason' => $reason,
                'user_uuid' => $freshUser['uuid'] ?? null,
            ],
        ]);

        return [
            'action' => 'adjust_user_points',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0,
            ],
            'delta' => $delta,
            'old_points' => $oldPoints,
            'new_points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0,
            'reason' => $reason,
        ];
    }

    private function createUserAccount(array $payload, array $logContext): array
    {
        $username = trim((string) ($payload['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('username is required.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('A valid email is required.');
        }

        $password = isset($payload['password']) ? trim((string) $payload['password']) : '';
        $passwordHash = trim((string) ($payload['password_hash'] ?? ''));
        if ($password === '' && $passwordHash === '') {
            throw new \RuntimeException('password is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
        if ($status === '') {
            $status = 'active';
        }

        $normalizedIsAdmin = $this->normalizeBooleanFilter($payload['is_admin'] ?? false);
        $isAdmin = $normalizedIsAdmin === true ? 1 : 0;

        $schoolId = isset($payload['school_id']) && is_numeric((string) $payload['school_id'])
            ? (int) $payload['school_id']
            : null;
        if ($schoolId !== null && $schoolId > 0 && !$this->schoolExists($schoolId)) {
            throw new \RuntimeException('School not found.');
        }

        $groupId = isset($payload['group_id']) && is_numeric((string) $payload['group_id'])
            ? (int) $payload['group_id']
            : null;
        if ($groupId !== null && $groupId > 0 && !$this->groupExists($groupId)) {
            throw new \RuntimeException('User group not found.');
        }

        $regionCode = trim((string) ($payload['region_code'] ?? ''));
        $regionCode = $regionCode !== '' ? $regionCode : null;

        $adminNotes = trim((string) ($payload['admin_notes'] ?? ''));
        $adminNotes = $adminNotes !== '' ? $adminNotes : null;

        $usernameCheck = $this->db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND deleted_at IS NULL LIMIT 1");
        $usernameCheck->execute([':username' => $username]);
        if ($usernameCheck->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \RuntimeException('Username already exists.');
        }

        $emailCheck = $this->db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND deleted_at IS NULL LIMIT 1");
        $emailCheck->execute([':email' => $email]);
        if ($emailCheck->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \RuntimeException('Email already exists.');
        }

        if ($passwordHash === '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new \RuntimeException('Unable to hash password.');
            }
        }

        $uuid = $this->generateEntityUuid();
        $timestamp = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("INSERT INTO users
            (username, email, password, uuid, school_id, group_id, region_code, admin_notes, status, is_admin, created_at, updated_at)
            VALUES
            (:username, :email, :password, :uuid, :school_id, :group_id, :region_code, :admin_notes, :status, :is_admin, :created_at, :updated_at)");
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => $passwordHash,
            ':uuid' => $uuid,
            ':school_id' => $schoolId,
            ':group_id' => $groupId,
            ':region_code' => $regionCode,
            ':admin_notes' => $adminNotes,
            ':status' => $status,
            ':is_admin' => $isAdmin,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        $userId = (int) $this->db->lastInsertId();
        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after creation.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_created', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'new_data' => [
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'uuid' => $freshUser['uuid'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'is_admin' => isset($freshUser['is_admin']) ? (bool) $freshUser['is_admin'] : false,
                'school_id' => $freshUser['school_id'] ?? null,
                'group_id' => $freshUser['group_id'] ?? null,
                'region_code' => $freshUser['region_code'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'username' => $username,
                'email' => $email,
                'status' => $status,
                'is_admin' => (bool) $isAdmin,
                'school_id' => $schoolId,
                'group_id' => $groupId,
                'region_code' => $regionCode,
                'admin_notes' => $adminNotes,
                'password_provided' => true,
            ],
        ]);

        return [
            'action' => 'create_user',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'is_admin' => isset($freshUser['is_admin']) ? (bool) $freshUser['is_admin'] : false,
                'school_id' => isset($freshUser['school_id']) ? (int) $freshUser['school_id'] : null,
                'school_name' => $freshUser['school_name'] ?? null,
                'group_id' => isset($freshUser['group_id']) ? (int) $freshUser['group_id'] : null,
                'group_name' => $freshUser['group_name'] ?? null,
                'region_code' => $freshUser['region_code'] ?? null,
            ],
        ];
    }

    private function updateUserStatus(array $payload, array $logContext): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if ($status === '') {
            throw new \RuntimeException('status is required.');
        }

        $adminNotesProvided = array_key_exists('admin_notes', $payload);
        $adminNotes = $adminNotesProvided ? trim((string) ($payload['admin_notes'] ?? '')) : null;
        if ($adminNotes === '' && $adminNotesProvided) {
            $adminNotes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $sets = ['status = :status', 'updated_at = :updated_at'];
        $params = [
            ':status' => $status,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':user_id' => $userId,
        ];

        if ($adminNotesProvided) {
            $sets[] = 'admin_notes = :admin_notes';
            $params[':admin_notes'] = $adminNotes;
        }

        $stmt = $this->db->prepare("UPDATE users
            SET " . implode(', ', $sets) . "
            WHERE id = :user_id
              AND deleted_at IS NULL");
        $stmt->execute($params);

        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_status_updated', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'old_data' => [
                'status' => $user['status'] ?? null,
                'admin_notes' => $user['admin_notes'] ?? null,
            ],
            'new_data' => [
                'status' => $freshUser['status'] ?? null,
                'admin_notes' => $freshUser['admin_notes'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $freshUser['uuid'] ?? null,
                'status' => $status,
                'admin_notes' => $adminNotes,
            ],
        ]);

        return [
            'action' => 'update_user_status',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'admin_notes' => $freshUser['admin_notes'] ?? null,
            ],
            'old_status' => $user['status'] ?? null,
            'new_status' => $freshUser['status'] ?? null,
        ];
    }

    private function awardBadgeToUser(array $payload, array $logContext): array
    {
        if ($this->badgeService === null) {
            throw new \RuntimeException('Badge service unavailable.');
        }

        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $badgeId = isset($payload['badge_id']) && is_numeric((string) $payload['badge_id']) ? (int) $payload['badge_id'] : 0;
        if ($badgeId <= 0) {
            throw new \RuntimeException('badge_id is required.');
        }

        $badge = $this->fetchBadgeById($badgeId);
        if ($badge === null) {
            throw new \RuntimeException('Badge not found.');
        }

        $notes = isset($payload['notes']) ? trim((string) ($payload['notes'] ?? '')) : null;
        if ($notes === '') {
            $notes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->badgeService->awardBadge($badgeId, $userId, [
            'source' => 'manual',
            'admin_id' => $adminId,
            'notes' => $notes,
            'meta' => [
                'source' => 'admin_ai',
                'conversation_id' => $logContext['conversation_id'] ?? null,
                'request_id' => $logContext['request_id'] ?? null,
            ],
        ]);

        $assignment = $this->fetchUserBadgeAssignment($userId, $badgeId);
        if ($assignment === null) {
            throw new \RuntimeException('Badge award did not persist.');
        }

        $this->auditLogService?->logAdminOperation('badge_awarded_via_ai', $adminId, 'badge_management', [
            'table' => 'user_badges',
            'record_id' => $assignment['id'] ?? null,
            'new_data' => [
                'user_id' => $userId,
                'badge_id' => $badgeId,
                'status' => $assignment['status'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $user['uuid'] ?? null,
                'badge_id' => $badgeId,
                'notes' => $notes,
            ],
        ]);

        return [
            'action' => 'award_badge_to_user',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
            ],
            'badge' => [
                'id' => $badgeId,
                'code' => $badge['code'] ?? null,
                'name' => $this->resolveBadgeDisplayName($badge),
            ],
            'assignment' => [
                'id' => $assignment['id'] ?? null,
                'status' => $assignment['status'] ?? null,
            ],
        ];
    }

    private function revokeBadgeFromUser(array $payload, array $logContext): array
    {
        if ($this->badgeService === null) {
            throw new \RuntimeException('Badge service unavailable.');
        }

        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $badgeId = isset($payload['badge_id']) && is_numeric((string) $payload['badge_id']) ? (int) $payload['badge_id'] : 0;
        if ($badgeId <= 0) {
            throw new \RuntimeException('badge_id is required.');
        }

        $badge = $this->fetchBadgeById($badgeId);
        if ($badge === null) {
            throw new \RuntimeException('Badge not found.');
        }

        $notes = isset($payload['notes']) ? trim((string) ($payload['notes'] ?? '')) : null;
        if ($notes === '') {
            $notes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $before = $this->fetchUserBadgeAssignment($userId, $badgeId);
        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $revoked = $this->badgeService->revokeBadge($badgeId, $userId, $adminId ?? 0, $notes);
        if (!$revoked) {
            throw new \RuntimeException('Badge revoke failed.');
        }

        $assignment = $this->fetchUserBadgeAssignment($userId, $badgeId);
        if ($assignment === null) {
            throw new \RuntimeException('Badge revoke result missing.');
        }

        $this->auditLogService?->logAdminOperation('badge_revoked_via_ai', $adminId, 'badge_management', [
            'table' => 'user_badges',
            'record_id' => $assignment['id'] ?? null,
            'old_data' => $before === null ? null : [
                'status' => $before['status'] ?? null,
            ],
            'new_data' => [
                'status' => $assignment['status'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $user['uuid'] ?? null,
                'badge_id' => $badgeId,
                'notes' => $notes,
            ],
        ]);

        return [
            'action' => 'revoke_badge_from_user',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
            ],
            'badge' => [
                'id' => $badgeId,
                'code' => $badge['code'] ?? null,
                'name' => $this->resolveBadgeDisplayName($badge),
            ],
            'assignment' => [
                'id' => $assignment['id'] ?? null,
                'status' => $assignment['status'] ?? null,
            ],
        ];
    }

    private function updateExchangeStatus(array $payload, array $logContext): array
    {
        $exchangeId = trim((string) ($payload['exchange_id'] ?? ''));
        if ($exchangeId === '') {
            throw new \RuntimeException('exchange_id is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $allowedStatuses = ['processing', 'shipped', 'completed', 'cancelled', 'rejected'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new \RuntimeException('Invalid exchange status.');
        }

        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }
        $trackingNumber = isset($payload['tracking_number']) ? trim((string) $payload['tracking_number']) : null;
        if ($trackingNumber === '') {
            $trackingNumber = null;
        }

        $before = $this->fetchExchangeRecordById($exchangeId);
        if ($before === null) {
            throw new \RuntimeException('Exchange order not found.');
        }

        $stmt = $this->db->prepare("UPDATE point_exchanges
            SET status = :status,
                notes = :notes,
                tracking_number = :tracking_number,
                updated_at = :updated_at
            WHERE id = :exchange_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':tracking_number' => $trackingNumber,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':exchange_id' => $exchangeId,
        ]);

        $after = $this->fetchExchangeRecordById($exchangeId);
        if ($after === null) {
            throw new \RuntimeException('Exchange order not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('exchange_status_updated', $adminId, 'exchange_management', [
            'table' => 'point_exchanges',
            'record_id' => $exchangeId,
            'old_data' => [
                'status' => $before['status'] ?? null,
                'notes' => $before['notes'] ?? null,
                'tracking_number' => $before['tracking_number'] ?? null,
            ],
            'new_data' => [
                'status' => $after['status'] ?? null,
                'notes' => $after['notes'] ?? null,
                'tracking_number' => $after['tracking_number'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'exchange_id' => $exchangeId,
                'status' => $status,
                'notes' => $notes,
                'tracking_number' => $trackingNumber,
            ],
        ]);

        $this->sendExchangeStatusNotification($after, $status, $notes, $trackingNumber);

        return [
            'action' => 'update_exchange_status',
            'exchange' => [
                'id' => $after['id'] ?? null,
                'status' => $after['status'] ?? null,
                'product_name' => $after['product_name'] ?? null,
                'tracking_number' => $after['tracking_number'] ?? null,
                'username' => $after['username'] ?? null,
                'email' => $after['email'] ?? null,
            ],
        ];
    }

    private function updateProductStatus(array $payload, array $logContext): array
    {
        $productId = isset($payload['product_id']) && is_numeric((string) $payload['product_id']) ? (int) $payload['product_id'] : 0;
        if ($productId <= 0) {
            throw new \RuntimeException('product_id is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \RuntimeException('Invalid product status.');
        }

        $before = $this->fetchProductById($productId);
        if ($before === null) {
            throw new \RuntimeException('Product not found.');
        }

        $stmt = $this->db->prepare("UPDATE products
            SET status = :status,
                updated_at = :updated_at
            WHERE id = :product_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':product_id' => $productId,
        ]);

        $after = $this->fetchProductById($productId);
        if ($after === null) {
            throw new \RuntimeException('Product not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('product_status_updated', $adminId, 'product_management', [
            'table' => 'products',
            'record_id' => $productId,
            'old_data' => ['status' => $before['status'] ?? null],
            'new_data' => ['status' => $after['status'] ?? null],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'product_id' => $productId,
                'status' => $status,
            ],
        ]);

        return [
            'action' => 'update_product_status',
            'product' => [
                'id' => $productId,
                'name' => $after['name'] ?? null,
                'status' => $after['status'] ?? null,
                'stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            ],
        ];
    }

    private function adjustProductInventory(array $payload, array $logContext): array
    {
        $productId = isset($payload['product_id']) && is_numeric((string) $payload['product_id']) ? (int) $payload['product_id'] : 0;
        if ($productId <= 0) {
            throw new \RuntimeException('product_id is required.');
        }

        $before = $this->fetchProductById($productId);
        if ($before === null) {
            throw new \RuntimeException('Product not found.');
        }

        $targetStock = array_key_exists('target_stock', $payload) && is_numeric((string) $payload['target_stock'])
            ? (int) $payload['target_stock']
            : null;
        $stockDelta = array_key_exists('stock_delta', $payload) && is_numeric((string) $payload['stock_delta'])
            ? (int) $payload['stock_delta']
            : null;
        if ($targetStock === null && $stockDelta === null) {
            throw new \RuntimeException('Either target_stock or stock_delta is required.');
        }

        $oldStock = isset($before['stock']) ? (int) $before['stock'] : 0;
        $newStock = $targetStock ?? ($oldStock + (int) $stockDelta);
        if ($newStock < 0) {
            throw new \RuntimeException('Inventory cannot be negative.');
        }

        $reason = isset($payload['reason']) ? trim((string) ($payload['reason'] ?? '')) : null;
        if ($reason === '') {
            $reason = null;
        }

        $stmt = $this->db->prepare("UPDATE products
            SET stock = :stock,
                updated_at = :updated_at
            WHERE id = :product_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':stock' => $newStock,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':product_id' => $productId,
        ]);

        $after = $this->fetchProductById($productId);
        if ($after === null) {
            throw new \RuntimeException('Product not found after inventory update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('product_inventory_adjusted', $adminId, 'product_management', [
            'table' => 'products',
            'record_id' => $productId,
            'old_data' => ['stock' => $oldStock],
            'new_data' => ['stock' => isset($after['stock']) ? (int) $after['stock'] : 0],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'product_id' => $productId,
                'stock_delta' => $stockDelta,
                'target_stock' => $targetStock,
                'reason' => $reason,
            ],
        ]);

        return [
            'action' => 'adjust_product_inventory',
            'product' => [
                'id' => $productId,
                'name' => $after['name'] ?? null,
                'status' => $after['status'] ?? null,
                'stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            ],
            'old_stock' => $oldStock,
            'new_stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            'stock_delta' => $stockDelta,
            'reason' => $reason,
        ];
    }

    private function reviewCarbonRecords(string $action, array $payload, array $logContext): array
    {
        $recordIds = array_values(array_unique(array_filter(array_map(
            static fn ($item) => !is_array($item) && !is_object($item) ? trim((string) $item) : '',
            (array) ($payload['record_ids'] ?? [])
        ))));
        if ($recordIds === []) {
            throw new \RuntimeException('No record_ids provided.');
        }

        $reviewNote = isset($payload['review_note']) && is_string($payload['review_note']) ? trim($payload['review_note']) : null;
        if ($reviewNote === '') {
            $reviewNote = null;
        }

        $records = $this->fetchCarbonRecordsByIds($recordIds);
        if ($records === []) {
            throw new \RuntimeException('No records found for provided ids.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $reviewedAt = gmdate('Y-m-d H:i:s');
        $processed = [];
        $skipped = [];
        $recordsByUser = [];

        $this->db->beginTransaction();
        try {
            $updateStmt = $this->db->prepare("UPDATE carbon_records
                SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = :review_note
                WHERE id = :record_id");
            $pointsStmt = $this->db->prepare("UPDATE users SET points = COALESCE(points, 0) + :points WHERE id = :user_id");

            foreach ($records as $record) {
                $recordId = (string) ($record['id'] ?? '');
                if ($recordId === '') {
                    continue;
                }
                if (($record['status'] ?? '') !== 'pending') {
                    $skipped[] = ['id' => $recordId, 'status' => $record['status'] ?? null];
                    continue;
                }

                $updateStmt->execute([
                    ':status' => $newStatus,
                    ':reviewed_by' => $adminId,
                    ':reviewed_at' => $reviewedAt,
                    ':review_note' => $reviewNote,
                    ':record_id' => $recordId,
                ]);

                if ($action === 'approve') {
                    $points = (int) ($record['points_earned'] ?? 0);
                    $userId = (int) ($record['user_id'] ?? 0);
                    if ($points !== 0 && $userId > 0) {
                        $pointsStmt->execute([':points' => $points, ':user_id' => $userId]);
                    }
                }

                $processed[] = $recordId;
                $record['status'] = $newStatus;
                $record['review_note'] = $reviewNote;
                $userId = (int) ($record['user_id'] ?? 0);
                if ($userId > 0) {
                    $recordsByUser[$userId][] = $this->buildReviewSummaryRecord($record);
                }

                $this->auditLogService?->logAdminOperation(
                    'carbon_record_' . ($action === 'approve' ? 'approve' : 'reject'),
                    $adminId,
                    'carbon_management',
                    [
                        'table' => 'carbon_records',
                        'record_id' => $recordId,
                        'old_data' => ['status' => 'pending'],
                        'new_data' => ['status' => $newStatus, 'review_note' => $reviewNote],
                        'request_id' => $logContext['request_id'] ?? null,
                        'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
                        'request_method' => 'POST',
                        'conversation_id' => $logContext['conversation_id'] ?? null,
                    ]
                );
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        foreach ($recordsByUser as $userId => $userRecords) {
            if ($this->messageService !== null && $userRecords !== []) {
                $this->messageService->sendCarbonRecordReviewSummary($userId, $action, $userRecords, $reviewNote, [
                    'reviewed_by_id' => $adminId,
                ]);
            }
        }

        return [
            'action' => $action,
            'processed_ids' => $processed,
            'processed_count' => count($processed),
            'skipped' => $skipped,
            'review_note' => $reviewNote,
        ];
    }

    private function fetchHistoryMessages(string $conversationId): array
    {
        $stmt = $this->db->prepare("SELECT action, data
            FROM audit_logs
            WHERE conversation_id = :conversation_id
              AND action IN ('admin_ai_user_message', 'admin_ai_assistant_message', 'admin_ai_summary_snapshot')
            ORDER BY created_at ASC, id ASC");
        $stmt->execute([':conversation_id' => $conversationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $history = [];
        foreach ($rows as $row) {
            $data = $this->decodeJson($row['data'] ?? null);
            $content = trim((string) ($data['visible_text'] ?? ''));
            if ($content === '') {
                continue;
            }
            $history[] = [
                'role' => ($row['action'] ?? '') === 'admin_ai_user_message' ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        $maxHistory = max(2, (int) ($this->agentConfig['max_history_messages'] ?? 12));
        return count($history) > $maxHistory ? array_slice($history, -$maxHistory) : $history;
    }

    private function fetchConversationTimeline(string $conversationId): array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM audit_logs
            WHERE conversation_id = :conversation_id
              AND operation_category = 'admin_ai'
            ORDER BY created_at ASC, id ASC");
        $stmt->execute([':conversation_id' => $conversationId]);

        $messages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $data = $this->decodeJson($row['data'] ?? null);
            $kind = 'event';
            $role = 'assistant';
            $action = (string) ($row['action'] ?? '');
            if ($action === 'admin_ai_user_message') {
                $kind = 'message';
                $role = 'user';
            } elseif ($action === 'admin_ai_assistant_message') {
                $kind = 'message';
                $role = 'assistant';
            } elseif ($action === 'admin_ai_action_proposed') {
                $kind = 'action_proposed';
            } elseif (str_starts_with($action, 'admin_ai_action_')) {
                $kind = 'action_event';
            } elseif ($action === 'admin_ai_tool_invocation') {
                $kind = 'tool';
            }

            $proposal = null;
            if ($action === 'admin_ai_action_proposed') {
                $proposal = [
                    'proposal_id' => (int) ($row['id'] ?? 0),
                    'action_name' => $data['action_name'] ?? null,
                    'label' => $data['label'] ?? null,
                    'summary' => $data['summary'] ?? ($data['visible_text'] ?? null),
                    'payload' => $data['payload'] ?? null,
                    'risk_level' => $data['risk_level'] ?? null,
                    'status' => $row['status'] ?? null,
                ];
            }

            $messages[] = [
                'id' => (int) ($row['id'] ?? 0),
                'kind' => $kind,
                'role' => $role,
                'action' => $action,
                'status' => $row['status'] ?? null,
                'content' => $data['visible_text'] ?? null,
                'proposal' => $proposal,
                'meta' => [
                    'request_id' => $row['request_id'] ?? null,
                    'response_code' => $row['response_code'] !== null ? (int) $row['response_code'] : null,
                    'data' => $data,
                ],
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return $messages;
    }

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

    private function fetchConversationPreview(string $conversationId): array
    {
        $stmt = $this->db->prepare("SELECT action, data
            FROM audit_logs
            WHERE conversation_id = :conversation_id
              AND action IN ('admin_ai_user_message', 'admin_ai_assistant_message')
            ORDER BY created_at ASC, id ASC");
        $stmt->execute([':conversation_id' => $conversationId]);

        $title = null;
        $lastMessage = null;
        $messageCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $data = $this->decodeJson($row['data'] ?? null);
            $visibleText = trim((string) ($data['visible_text'] ?? ''));
            if ($visibleText === '') {
                continue;
            }
            $messageCount++;
            if ($title === null && ($row['action'] ?? '') === 'admin_ai_user_message') {
                $title = $this->buildPreview($visibleText, 80);
            }
            $lastMessage = $this->buildPreview($visibleText, 120);
        }

        return [
            'title' => $title,
            'last_message_preview' => $lastMessage,
            'message_count' => $messageCount,
        ];
    }

    private function countPendingActions(string $conversationId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs
            WHERE conversation_id = :conversation_id
              AND action = 'admin_ai_action_proposed'
              AND status = 'pending'");
        $stmt->execute([':conversation_id' => $conversationId]);
        return (int) $stmt->fetchColumn();
    }

    private function findProposal(string $conversationId, int $proposalId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM audit_logs
            WHERE id = :id
              AND conversation_id = :conversation_id
              AND action = 'admin_ai_action_proposed'");
        $stmt->execute([':id' => $proposalId, ':conversation_id' => $conversationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $data = $this->decodeJson($row['data'] ?? null);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'status' => $row['status'] ?? null,
            'action_name' => $data['action_name'] ?? null,
            'payload' => $data['payload'] ?? null,
        ];
    }

    private function updateProposalStatus(int $proposalId, string $status, array $meta = []): void
    {
        $stmt = $this->db->prepare("SELECT data FROM audit_logs WHERE id = :id");
        $stmt->execute([':id' => $proposalId]);
        $existing = $this->decodeJson($stmt->fetchColumn() ?: null);
        $merged = array_merge($existing, ['decision_meta' => $meta]);

        $update = $this->db->prepare("UPDATE audit_logs SET status = :status, data = :data WHERE id = :id");
        $update->execute([
            ':status' => $status,
            ':data' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $proposalId,
        ]);
    }

    private function logConversationEvent(string $action, array $logContext, array $payload): void
    {
        if ($this->auditLogService === null) {
            return;
        }

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
                    'record_id' => isset($payload['proposal_id']) ? (int) $payload['proposal_id'] : null,
                    'table' => 'audit_logs',
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to write admin AI conversation audit log.', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function logLlmCall(
        array $messages,
        array $rawResponse,
        array $logContext,
        array $context,
        string $conversationId,
        int $turnNo,
        float $startedAt
    ): void {
        if ($this->llmLogService === null) {
            return;
        }

        $choice = $rawResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $responseId = $rawResponse['id'] ?? ($rawResponse['response_id'] ?? null);
        $responseText = isset($message['content']) ? (string) $message['content'] : json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'conversation_id' => $conversationId,
            'turn_no' => $turnNo,
            'source' => $logContext['source'] ?? '/admin/ai/chat',
            'model' => $rawResponse['model'] ?? $this->model,
            'prompt' => $messages,
            'response_raw' => $responseText,
            'response_id' => is_string($responseId) ? $responseId : null,
            'status' => 'success',
            'usage' => $rawResponse['usage'] ?? null,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'context' => [
                'conversation_context' => $context,
                'tool_calls' => $message['tool_calls'] ?? [],
            ],
        ]);
    }

    private function logLlmFailure(
        array $messages,
        array $logContext,
        array $context,
        string $conversationId,
        int $turnNo,
        float $startedAt,
        \Throwable $exception
    ): void {
        if ($this->llmLogService === null) {
            return;
        }

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'conversation_id' => $conversationId,
            'turn_no' => $turnNo,
            'source' => $logContext['source'] ?? '/admin/ai/chat',
            'model' => $this->model,
            'prompt' => $messages,
            'response_raw' => null,
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'context' => [
                'conversation_context' => $context,
            ],
        ]);
    }

    private function logError(\Throwable $exception, array $logContext, array $extra = []): void
    {
        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext(
                $logContext['source'] ?? '/admin/ai/chat',
                'POST',
                isset($logContext['request_id']) ? (string) $logContext['request_id'] : null,
                [],
                $extra
            );
            $this->errorLogService->logException($exception, $request, $extra);
        } catch (\Throwable $loggingError) {
            $this->logger->warning('Failed to persist admin AI error log.', [
                'error' => $loggingError->getMessage(),
                'original_error' => $exception->getMessage(),
            ]);
        }
    }

    private function extractMetadata(array $rawResponse): array
    {
        $choice = $rawResponse['choices'][0] ?? [];
        $finishReason = $choice['finish_reason'] ?? null;

        return array_filter([
            'model' => $rawResponse['model'] ?? $this->model,
            'usage' => is_array($rawResponse['usage'] ?? null) ? $rawResponse['usage'] : null,
            'finish_reason' => is_string($finishReason) ? $finishReason : null,
            'response_id' => isset($rawResponse['id']) && is_string($rawResponse['id']) ? $rawResponse['id'] : null,
        ], static fn ($value) => $value !== null);
    }

    private function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach (self::ALLOWED_CONTEXT_KEYS as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $value = $context[$key];
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $normalized[$key] = $trimmed;
                }
                continue;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                $normalized[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $normalized[$key] = array_values(array_filter($value, static fn ($item) => !is_array($item) && !is_object($item) && trim((string) $item) !== ''));
            }
        }

        return $normalized;
    }

    private function normalizeConversationId(?string $conversationId): ?string
    {
        if (!is_string($conversationId)) {
            return null;
        }

        $normalized = trim($conversationId);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $normalized) === 1 ? $normalized : null;
    }

    private function normalizeDateBoundary(mixed $value, bool $endOfDay): ?string
    {
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

    private function generateConversationId(): string
    {
        try {
            return 'admin-ai-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'admin-ai-' . str_replace('.', '', uniqid('', true));
        }
    }

    private function generateEntityUuid(): string
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            );
        } catch (\Throwable) {
            return strtolower(sprintf(
                '%08s-%04s-4%03s-%04s-%012s',
                substr(md5(uniqid('user', true)), 0, 8),
                substr(md5(uniqid('user', true)), 8, 4),
                substr(md5(uniqid('user', true)), 12, 3),
                substr(md5(uniqid('user', true)), 15, 4),
                substr(md5(uniqid('user', true)), 19, 12)
            ));
        }
    }

    private function getNextTurnNo(string $conversationId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(turn_no), 0) FROM llm_logs WHERE conversation_id = :conversation_id');
        $stmt->execute([':conversation_id' => $conversationId]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    private function resolveUserRowFromPayload(array $payload): ?array
    {
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;
        $userUuid = strtolower(trim((string) ($payload['user_uuid'] ?? '')));

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($userId !== null && $userId > 0) {
            $where[] = 'u.id = :user_id';
            $params[':user_id'] = $userId;
        } elseif ($userUuid !== '') {
            $where[] = 'LOWER(u.uuid) = :user_uuid';
            $params[':user_uuid'] = $userUuid;
        } else {
            return null;
        }

        $stmt = $this->db->prepare("SELECT u.*, s.name AS school_name, g.name AS group_name
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN user_groups g ON g.id = u.group_id
            WHERE " . implode(' AND ', $where) . "
            LIMIT 1");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function schoolExists(int $schoolId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM schools WHERE id = :school_id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':school_id' => $schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function groupExists(int $groupId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM user_groups WHERE id = :group_id LIMIT 1");
        $stmt->execute([':group_id' => $groupId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function fetchBadgeById(int $badgeId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM achievement_badges
            WHERE id = :badge_id
              AND deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':badge_id' => $badgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function fetchUserBadgeAssignment(int $userId, int $badgeId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM user_badges
            WHERE user_id = :user_id
              AND badge_id = :badge_id
            ORDER BY id DESC
            LIMIT 1");
        $stmt->execute([
            ':user_id' => $userId,
            ':badge_id' => $badgeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resolveBadgeDisplayName(array $badge): string
    {
        $name = trim((string) ($badge['name_zh'] ?? $badge['name_en'] ?? $badge['code'] ?? ''));
        return $name !== '' ? $name : '未命名徽章';
    }

    private function fetchProductById(int $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM products
            WHERE id = :product_id
              AND deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':product_id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function preparePersistedActionPayload(string $actionName, array $payload): array
    {
        if ($actionName !== 'create_user') {
            return $payload;
        }

        $sanitized = $payload;
        $password = isset($sanitized['password']) ? trim((string) $sanitized['password']) : '';
        if ($password !== '' && empty($sanitized['password_hash'])) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new \RuntimeException('Unable to hash password.');
            }
            $sanitized['password_hash'] = $passwordHash;
        }

        unset($sanitized['password']);
        if (!empty($sanitized['password_hash'])) {
            $sanitized['password_provided'] = true;
        }

        return $sanitized;
    }

    private function resolvePointExchangeUserColumn(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = 'user_id';
        try {
            $driver = (string) ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql');
            if ($driver === 'sqlite') {
                $stmt = $this->db->query("PRAGMA table_info(point_exchanges)");
                $columns = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                $names = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);
                if (!in_array('user_id', $names, true) && in_array('uid', $names, true)) {
                    $resolved = 'uid';
                }
            }
        } catch (\Throwable) {
        }

        return $resolved;
    }

    private function fetchExchangeRecordById(string $exchangeId): ?array
    {
        $userColumn = $this->resolvePointExchangeUserColumn();
        $stmt = $this->db->prepare("SELECT e.*, u.username, u.email
            FROM point_exchanges e
            LEFT JOIN users u ON u.id = e.{$userColumn}
            WHERE e.id = :exchange_id
              AND e.deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':exchange_id' => $exchangeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function sendExchangeStatusNotification(array $exchange, string $status, ?string $notes, ?string $trackingNumber): void
    {
        if ($this->messageService === null) {
            return;
        }

        $statusMessages = [
            'processing' => '您的兑换订单正在处理中',
            'shipped' => '您的兑换商品已发货',
            'completed' => '您的兑换订单已完成',
            'cancelled' => '您的兑换订单已取消',
            'rejected' => '您的兑换订单已被驳回',
        ];
        $title = $statusMessages[$status] ?? '兑换状态更新';
        $message = sprintf(
            '您的兑换订单（%s x%s）状态已更新为：%s',
            (string) ($exchange['product_name'] ?? '未知商品'),
            (string) ($exchange['quantity'] ?? '1'),
            $title
        );
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $message .= "\n物流单号：" . $trackingNumber;
        }
        if ($notes !== null && $notes !== '') {
            $message .= "\n备注：" . $notes;
        }

        $userColumn = $this->resolvePointExchangeUserColumn();
        $userId = isset($exchange[$userColumn]) ? (int) $exchange[$userColumn] : 0;
        if ($userId <= 0) {
            return;
        }

        $this->messageService->sendMessage(
            $userId,
            'exchange_status_updated',
            $title,
            $message,
            'normal'
        );
        $this->messageService->sendExchangeStatusUpdateEmailToUser(
            $userId,
            (string) ($exchange['product_name'] ?? ''),
            $status,
            $trackingNumber,
            $notes,
            isset($exchange['email']) ? (string) $exchange['email'] : null,
            isset($exchange['username']) ? (string) $exchange['username'] : null
        );
    }

    /**
     * @param array<int,string> $recordIds
     * @return array<int,array<string,mixed>>
     */
    private function fetchCarbonRecordsByIds(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($recordIds) as $index => $recordId) {
            $placeholder = ':record_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $recordId;
        }

        $sql = "SELECT r.id, r.user_id, r.activity_id, r.status, r.date, r.carbon_saved, r.points_earned,
                       r.review_note, u.username, u.email, a.name_zh AS activity_name_zh, a.name_en AS activity_name_en
                FROM carbon_records r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE r.id IN (" . implode(',', $placeholders) . ')';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function buildReviewSummaryRecord(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'date' => $record['date'] ?? null,
            'status' => $record['status'] ?? null,
            'carbon_saved' => isset($record['carbon_saved']) ? (float) $record['carbon_saved'] : null,
            'points_earned' => isset($record['points_earned']) ? (int) $record['points_earned'] : null,
            'activity_name' => $record['activity_name_zh'] ?? ($record['activity_name_en'] ?? null),
            'review_note' => $record['review_note'] ?? null,
        ];
    }

    private function buildProposalSummary(array $definition, array $payload): string
    {
        $label = (string) ($definition['label'] ?? $definition['name'] ?? '后台操作');
        $recordIds = isset($payload['record_ids']) && is_array($payload['record_ids']) ? array_values($payload['record_ids']) : [];
        $segments = [$label];

        if ($recordIds !== []) {
            $segments[] = sprintf('记录 %s', implode(', ', array_map(static fn ($item) => (string) $item, $recordIds)));
        }
        if (!empty($payload['user_id'])) {
            $segments[] = sprintf('用户 #%s', (string) $payload['user_id']);
        } elseif (!empty($payload['user_uuid'])) {
            $segments[] = sprintf('用户 UUID %s', (string) $payload['user_uuid']);
        }
        if (!empty($payload['exchange_id'])) {
            $segments[] = sprintf('兑换单 %s', (string) $payload['exchange_id']);
        }
        if (!empty($payload['badge_id'])) {
            $segments[] = sprintf('徽章 #%s', (string) $payload['badge_id']);
        }
        if (!empty($payload['product_id'])) {
            $segments[] = sprintf('商品 #%s', (string) $payload['product_id']);
        }
        if (!empty($payload['username'])) {
            $segments[] = sprintf('用户名 %s', (string) $payload['username']);
        }
        if (!empty($payload['email'])) {
            $segments[] = sprintf('邮箱 %s', (string) $payload['email']);
        }
        if (!empty($payload['region_code'])) {
            $segments[] = sprintf('地区 %s', (string) $payload['region_code']);
        }
        if (isset($payload['delta']) && is_numeric((string) $payload['delta'])) {
            $segments[] = sprintf('积分变动 %s', (string) $payload['delta']);
        }
        if (isset($payload['stock_delta']) && is_numeric((string) $payload['stock_delta'])) {
            $segments[] = sprintf('库存增量 %s', (string) $payload['stock_delta']);
        }
        if (isset($payload['target_stock']) && is_numeric((string) $payload['target_stock'])) {
            $segments[] = sprintf('目标库存 %s', (string) $payload['target_stock']);
        }
        if (!empty($payload['status'])) {
            $segments[] = sprintf('状态 %s', (string) $payload['status']);
        }
        if (!empty($payload['review_note'])) {
            $segments[] = sprintf('备注：%s', trim((string) $payload['review_note']));
        }
        if (!empty($payload['notes'])) {
            $segments[] = sprintf('备注：%s', trim((string) $payload['notes']));
        }
        if (!empty($payload['reason'])) {
            $segments[] = sprintf('原因：%s', trim((string) $payload['reason']));
        }
        if (!empty($payload['admin_notes'])) {
            $segments[] = sprintf('管理员备注：%s', trim((string) $payload['admin_notes']));
        }
        if (!empty($payload['tracking_number'])) {
            $segments[] = sprintf('物流单号：%s', trim((string) $payload['tracking_number']));
        }
        if (!empty($payload['days'])) {
            $segments[] = sprintf('范围 %d 天', (int) $payload['days']);
        }

        return implode('；', $segments);
    }

    private function formatReadActionResult(string $actionName, array $result): string
    {
        return match ($actionName) {
            'get_admin_stats' => sprintf(
                '后台总览：用户 %s，待审核记录 %s，累计减排 %s kg。',
                $this->safeReadValue($result, ['data', 'user_count'], '0'),
                $this->safeReadValue($result, ['data', 'pending_records'], '0'),
                $this->safeReadValue($result, ['data', 'total_carbon_saved'], '0')
            ),
            'get_pending_carbon_records' => sprintf(
                '当前共有 %d 条待处理记录。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeRecordList((array) ($result['items'] ?? []))
            ),
            'get_llm_usage_analytics' => sprintf(
                '近 %d 天 LLM 调用 %d 次，共 %d tokens，主要模型为 %s。',
                (int) ($result['days'] ?? 0),
                (int) ($result['total_calls'] ?? 0),
                (int) ($result['total_tokens'] ?? 0),
                (string) ($result['top_model'] ?? '未知')
            ),
            'get_activity_statistics' => $this->summarizeActivityStats((array) ($result['items'] ?? [])),
            'generate_admin_report' => sprintf(
                '已生成 %d 天管理摘要：待处理记录 %d 条，LLM 调用 %d 次。',
                (int) ($result['days'] ?? 0),
                (int) ($result['pending']['total'] ?? 0),
                (int) ($result['llm']['total_calls'] ?? 0)
            ),
            'search_users' => sprintf(
                '匹配到 %d 位用户。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeUserList((array) ($result['items'] ?? []))
            ),
            'get_user_overview' => sprintf(
                '用户 %s：状态 %s，积分 %d，累计减排 %.2f kg，Passkey %d 个。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['user']['status'] ?? 'unknown'),
                (int) ($result['user']['points'] ?? 0),
                (float) ($result['metrics']['total_carbon_saved'] ?? 0),
                (int) ($result['metrics']['passkey_count'] ?? 0)
            ),
            'get_exchange_orders' => sprintf(
                '匹配到 %d 条兑换单。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeExchangeList((array) ($result['items'] ?? []))
            ),
            'get_exchange_order_detail' => sprintf(
                '兑换单 %s：用户 %s，商品 %s，状态 %s，积分 %s。',
                (string) ($result['exchange']['id'] ?? '-'),
                (string) ($result['exchange']['username'] ?? '未知用户'),
                (string) ($result['exchange']['product_name'] ?? '未知商品'),
                (string) ($result['exchange']['status'] ?? 'unknown'),
                (string) ($result['exchange']['points_used'] ?? '0')
            ),
            'get_product_catalog' => sprintf(
                '商品列表共匹配 %d 项。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeProductList((array) ($result['items'] ?? []))
            ),
            'get_passkey_admin_stats' => sprintf(
                '当前共有 %d 个 Passkey，覆盖 %d 位用户，近 30 天活跃 %d 个。',
                (int) ($result['total_passkeys'] ?? 0),
                (int) ($result['users_with_passkeys'] ?? 0),
                (int) ($result['used_recently_30d'] ?? 0)
            ),
            'get_passkey_admin_list' => sprintf(
                '匹配到 %d 个 Passkey。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizePasskeyList((array) ($result['items'] ?? []))
            ),
            'search_system_logs' => sprintf(
                '日志检索返回 %d 条结果。%s',
                (int) ($result['returned_count'] ?? 0),
                $this->summarizeLogList((array) ($result['items'] ?? []))
            ),
            'get_broadcast_history' => sprintf(
                '广播历史共 %d 条。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeBroadcastList((array) ($result['items'] ?? []))
            ),
            'search_broadcast_recipients' => sprintf(
                '匹配到 %d 位候选接收人。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeUserList((array) ($result['items'] ?? []))
            ),
            default => '已完成查询。'
        };
    }

    private function formatWriteActionResult(string $actionName, array $result): string
    {
        return match ($actionName) {
            'approve_carbon_records' => sprintf(
                '已批准 %d 条记录。%s',
                (int) ($result['processed_count'] ?? 0),
                $this->formatSkippedSummary((array) ($result['skipped'] ?? []))
            ),
            'reject_carbon_records' => sprintf(
                '已驳回 %d 条记录。%s',
                (int) ($result['processed_count'] ?? 0),
                $this->formatSkippedSummary((array) ($result['skipped'] ?? []))
            ),
            'adjust_user_points' => sprintf(
                '已为用户 %s 调整积分 %s，当前积分 %d。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['delta'] ?? '0'),
                (int) ($result['new_points'] ?? 0)
            ),
            'create_user' => sprintf(
                '已创建用户 %s（%s），状态 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['user']['email'] ?? '-'),
                (string) ($result['user']['status'] ?? 'unknown')
            ),
            'update_user_status' => sprintf(
                '已将用户 %s 的状态更新为 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['new_status'] ?? 'unknown')
            ),
            'award_badge_to_user' => sprintf(
                '已向用户 %s 发放徽章 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['badge']['name'] ?? '未命名徽章')
            ),
            'revoke_badge_from_user' => sprintf(
                '已撤销用户 %s 的徽章 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['badge']['name'] ?? '未命名徽章')
            ),
            'update_exchange_status' => sprintf(
                '兑换单 %s 已更新为 %s。',
                (string) ($result['exchange']['id'] ?? '-'),
                (string) ($result['exchange']['status'] ?? 'unknown')
            ),
            'update_product_status' => sprintf(
                '商品 %s 已更新为 %s。',
                (string) ($result['product']['name'] ?? '未命名商品'),
                (string) ($result['product']['status'] ?? 'unknown')
            ),
            'adjust_product_inventory' => sprintf(
                '商品 %s 库存已从 %d 调整到 %d。',
                (string) ($result['product']['name'] ?? '未命名商品'),
                (int) ($result['old_stock'] ?? 0),
                (int) ($result['new_stock'] ?? 0)
            ),
            default => '操作已执行。'
        };
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeRecordList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配记录。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s %skg',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['carbon_saved'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . (count($items) > 3 ? '。' : '。');
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeActivityStats(array $items): string
    {
        if ($items === []) {
            return '当前没有可汇总的活动统计数据。';
        }

        $top = $items[0];
        return sprintf(
            '活动统计已整理，当前领先项为“%s”，通过 %d 条，待处理 %d 条。',
            (string) ($top['activity_name'] ?? '未命名活动'),
            (int) ($top['approved_count'] ?? 0),
            (int) ($top['pending_count'] ?? 0)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeUserList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配用户。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（%s，积分 %s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['status'] ?? 'unknown'),
                (string) ($item['points'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeExchangeList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配兑换单。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s / %s / %s',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['product_name'] ?? '未知商品'),
                (string) ($item['status'] ?? 'unknown')
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeProductList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配商品。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（%s 积分，库存 %s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['name'] ?? '未命名商品'),
                (string) ($item['points_required'] ?? 0),
                (string) ($item['stock'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizePasskeyList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配 Passkey。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s / sign_count=%s',
                (string) ($item['id'] ?? '-'),
                (string) (($item['username'] ?? null) ?: ($item['user_uuid'] ?? '未知用户')),
                (string) ($item['sign_count'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeLogList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配日志。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '[%s] %s',
                (string) ($item['type'] ?? 'log'),
                (string) ($item['summary'] ?? '无摘要')
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeBroadcastList(array $items): string
    {
        if ($items === []) {
            return '当前没有广播历史。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（发送 %s/%s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['title'] ?? '未命名广播'),
                (string) ($item['sent_count'] ?? 0),
                (string) ($item['target_count'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,string> $path
     */
    private function safeReadValue(array $result, array $path, string $fallback): string
    {
        $cursor = $result;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $fallback;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor === null || $cursor === '' ? $fallback : (string) $cursor;
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
    private function decodeJson($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,array<string,mixed>> $skipped
     */
    private function formatSkippedSummary(array $skipped): string
    {
        if ($skipped === []) {
            return '没有跳过项。';
        }

        $parts = [];
        foreach (array_slice($skipped, 0, 3) as $item) {
            $parts[] = sprintf('#%s %s', (string) ($item['id'] ?? '-'), (string) ($item['reason'] ?? 'skipped'));
        }

        return '跳过：' . implode('；', $parts) . (count($skipped) > 3 ? ' 等。' : '。');
    }
}
