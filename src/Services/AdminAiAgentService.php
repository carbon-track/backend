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

    private AdminAiConversationStoreService $conversationStoreService;
    private AdminAiReadModelService $readModelService;
    private AdminAiWriteActionService $writeActionService;
    private AdminAiResultFormatterService $resultFormatterService;

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
        private ?BadgeService $badgeService = null,
        ?AdminAiReadModelService $readModelService = null,
        ?AdminAiWriteActionService $writeActionService = null,
        ?AdminAiConversationStoreService $conversationStoreService = null,
        ?AdminAiResultFormatterService $resultFormatterService = null
    ) {
        $this->model = (string) ($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.2;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 900;
        $this->enabled = $client !== null;
        $this->conversationStoreService = $conversationStoreService ?? new AdminAiConversationStoreService($db, $logger, $this->auditLogService);
        $this->readModelService = $readModelService ?? new AdminAiReadModelService($db, $this->statisticsService);
        $this->writeActionService = $writeActionService ?? new AdminAiWriteActionService($db, $this->auditLogService, $this->messageService, $this->badgeService);
        $this->resultFormatterService = $resultFormatterService ?? new AdminAiResultFormatterService();
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

        $this->conversationStoreService->logConversationEvent('admin_ai_user_message', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $normalizedMessage,
            'role' => 'user',
            'context' => $normalizedContext,
        ]);

        $turnNo = $this->conversationStoreService->getNextTurnNo($conversationId);
        $history = $this->conversationStoreService->fetchHistoryMessages(
            $conversationId,
            max(2, (int) ($this->agentConfig['max_history_messages'] ?? 12))
        );
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->buildMessages($history, $normalizedMessage, $normalizedContext),
            'tools' => $this->buildTools(),
            'tool_choice' => 'auto',
        ];

        $startedAt = microtime(true);
        $llmLogId = null;
        try {
            $rawResponse = $this->client->createChatCompletion($payload);
            $llmLogId = $this->logLlmCall($payload['messages'], $rawResponse, $logContext, $normalizedContext, $conversationId, $turnNo, $startedAt);
        } catch (\Throwable $exception) {
            $this->logLlmFailure($payload['messages'], $logContext, $normalizedContext, $conversationId, $turnNo, $startedAt, $exception);
            $this->logError($exception, $logContext, [
                'conversation_id' => $conversationId,
                'message' => $normalizedMessage,
                'context' => $normalizedContext,
            ]);
            throw new \RuntimeException('LLM_UNAVAILABLE', 0, $exception);
        }

        $outcome = $this->processModelResponse($conversationId, $normalizedMessage, $normalizedContext, $logContext, $rawResponse);
        $this->updateLlmConversationSnapshot($llmLogId, $normalizedMessage, $outcome, $normalizedContext);

        if (($outcome['assistant_text'] ?? '') !== '') {
            $this->conversationStoreService->logConversationEvent('admin_ai_assistant_message', $logContext, [
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
        return $this->conversationStoreService->listConversations($filters);
    }


    /**
     * @return array<string,mixed>
     */
    public function getConversationDetail(string $conversationId): array
    {
        return $this->conversationStoreService->getConversationDetail($conversationId);
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
                    '- %s (%s): %s [risk=%s, confirm=%s]',
                    $name,
                    (string) ($definition['label'] ?? $name),
                    (string) ($definition['description'] ?? $definition['label'] ?? $name),
                    (string) ($definition['risk_level'] ?? 'read'),
                    !empty($definition['requires_confirmation']) ? 'yes' : 'no'
                );

                $keywords = array_values(array_filter(
                    is_array($definition['keywords'] ?? null) ? $definition['keywords'] : [],
                    static fn ($item): bool => is_string($item) && trim($item) !== ''
                ));
                if ($keywords !== []) {
                    $lines[] = '  keywords: ' . implode(', ', $keywords);
                }
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

    private function processModelResponse(string $conversationId, string $userMessage, array $context, array $logContext, array $rawResponse): array
    {
        $choice = $rawResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];
        $content = isset($message['content']) ? trim((string) $message['content']) : '';

        if ($toolCalls === []) {
            $fallback = $this->resolveKeywordFallbackAction($userMessage, $content);
            if ($fallback !== null) {
                return $this->handleManageAdminTool($conversationId, $fallback, $context, $logContext, $rawResponse);
            }

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
        $toolLabel = (string) ($definition['label'] ?? $actionName);
        $toolSummary = $this->resultFormatterService->buildProposalSummary($definition, $payload);

        $this->conversationStoreService->logConversationEvent('admin_ai_tool_invocation', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => sprintf('调用工具：%s', $toolLabel),
            'tool_name' => $actionName,
            'action_name' => $actionName,
            'label' => $toolLabel,
            'summary' => $toolSummary,
            'request_data' => [
                'action_name' => $actionName,
                'label' => $toolLabel,
                'summary' => $toolSummary,
                'payload' => $persistedPayload,
            ],
        ]);

        $isReadAction = ($definition['risk_level'] ?? 'read') === 'read' && empty($definition['requires_confirmation']);
        if ($isReadAction) {
            $result = $this->executeReadAction($actionName, $payload);
            return [
                'assistant_text' => $this->resultFormatterService->formatReadActionResult($actionName, $result),
                'result' => $result,
                'metadata' => $this->extractMetadata($rawResponse),
                'meta' => ['action_name' => $actionName],
            ];
        }

        $summary = $this->resultFormatterService->buildProposalSummary($definition, $payload);
        $proposalData = [
            'conversation_id' => $conversationId,
            'action_name' => $actionName,
            'label' => $definition['label'] ?? $actionName,
            'summary' => $summary,
            'payload' => $persistedPayload,
            'risk_level' => $definition['risk_level'] ?? 'write',
            'requires_confirmation' => true,
        ];

        $proposalId = $this->conversationStoreService->logConversationEvent('admin_ai_action_proposed', $logContext, [
            'conversation_id' => $conversationId,
            'visible_text' => $summary,
            'request_data' => $proposalData,
            'status' => 'pending',
        ]);

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

        $proposal = $this->conversationStoreService->findProposal($conversationId, $proposalId);
        if ($proposal === null) {
            throw new \RuntimeException('PROPOSAL_NOT_FOUND');
        }

        $actionName = (string) ($proposal['action_name'] ?? '');
        $payload = is_array($proposal['payload'] ?? null) ? $proposal['payload'] : [];

        if ($outcome === 'reject') {
            $this->conversationStoreService->updateProposalStatus($proposalId, 'failed', ['decision' => 'rejected']);
            $this->conversationStoreService->logConversationEvent('admin_ai_action_rejected', $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
                'request_data' => $payload,
            ]);
            $assistantText = '已取消该待执行操作。你可以补充条件后重新下达指令。';
            $this->conversationStoreService->logConversationEvent('admin_ai_assistant_message', $logContext, [
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

            $this->conversationStoreService->logConversationEvent('admin_ai_action_confirmed', $logContext, [
            'conversation_id' => $conversationId,
            'proposal_id' => $proposalId,
            'action_name' => $actionName,
            'request_data' => $payload,
        ]);

        try {
            $executeLogContext = $logContext;
            $executeLogContext['conversation_id'] = $conversationId;
            $result = $this->executeWriteAction($actionName, $payload, $executeLogContext);
            $this->conversationStoreService->updateProposalStatus($proposalId, 'success', ['decision' => 'confirmed', 'result' => $result]);
            $this->conversationStoreService->logConversationEvent('admin_ai_action_executed', $logContext, [
                'conversation_id' => $conversationId,
                'proposal_id' => $proposalId,
                'action_name' => $actionName,
                'request_data' => $payload,
                'new_data' => $result,
            ]);
            $assistantText = $this->resultFormatterService->formatWriteActionResult($actionName, $result);
            $meta = ['decision' => 'confirmed', 'proposal_id' => $proposalId, 'result' => $result];
        } catch (\Throwable $exception) {
            $this->conversationStoreService->updateProposalStatus($proposalId, 'failed', ['decision' => 'confirmed', 'error' => $exception->getMessage()]);
            $this->conversationStoreService->logConversationEvent('admin_ai_action_failed', $logContext, [
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

        $this->conversationStoreService->logConversationEvent('admin_ai_assistant_message', $logContext, [
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
        return $this->readModelService->execute($actionName, $payload);
    }

    private function executeWriteAction(string $actionName, array $payload, array $logContext): array
    {
        return $this->writeActionService->execute($actionName, $payload, $logContext);
    }

    private function logLlmCall(
        array $messages,
        array $rawResponse,
        array $logContext,
        array $context,
        string $conversationId,
        int $turnNo,
        float $startedAt
    ): ?int {
        if ($this->llmLogService === null) {
            return null;
        }

        $choice = $rawResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $responseId = $rawResponse['id'] ?? ($rawResponse['response_id'] ?? null);
        $responseText = isset($message['content']) ? (string) $message['content'] : json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->llmLogService->log([
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

    /**
     * @param array<string,mixed> $outcome
     * @param array<string,mixed> $context
     */
    private function updateLlmConversationSnapshot(?int $llmLogId, string $userMessage, array $outcome, array $context): void
    {
        if ($llmLogId === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare('SELECT context_json FROM llm_logs WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $llmLogId]);
            $existing = $this->decodeJson($stmt->fetchColumn() ?: null);
            $existing['conversation_context'] = $context;
            $existing['conversation_snapshot'] = array_filter([
                'user_message' => $userMessage !== '' ? $userMessage : null,
                'assistant_message' => isset($outcome['assistant_text']) && trim((string) $outcome['assistant_text']) !== ''
                    ? trim((string) $outcome['assistant_text'])
                    : null,
                'suggestion' => isset($outcome['suggestion']) && is_array($outcome['suggestion']) ? $outcome['suggestion'] : null,
                'proposal' => isset($outcome['proposal']) && is_array($outcome['proposal']) ? $outcome['proposal'] : null,
                'result' => isset($outcome['result']) && is_array($outcome['result']) ? $outcome['result'] : null,
                'meta' => isset($outcome['meta']) && is_array($outcome['meta']) ? $outcome['meta'] : null,
                'metadata' => isset($outcome['metadata']) && is_array($outcome['metadata']) ? $outcome['metadata'] : null,
            ], static fn ($value) => $value !== null && $value !== '');

            $update = $this->db->prepare('UPDATE llm_logs SET context_json = :context_json WHERE id = :id');
            $update->execute([
                ':context_json' => json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $llmLogId,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to update admin AI LLM conversation snapshot.', [
                'llm_log_id' => $llmLogId,
                'error' => $exception->getMessage(),
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

    /**
     * @return array<string,mixed>|null
     */
    private function resolveKeywordFallbackAction(string $userMessage, string $assistantContent = ''): ?array
    {
        if ($this->actionDefinitions === []) {
            return null;
        }

        $combined = trim($userMessage . ' ' . $assistantContent);
        if ($combined === '') {
            return null;
        }

        $normalizedText = $this->normalizeMatchText($combined);
        $bestAction = null;
        $bestScore = 0;

        foreach ($this->actionDefinitions as $name => $definition) {
            $score = $this->scoreActionKeywordMatch($normalizedText, $name, $definition);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAction = $name;
            }
        }

        if ($bestAction === null || $bestScore < 2) {
            return null;
        }

        return [
            'action' => $bestAction,
            'payload' => [],
        ];
    }

    private function scoreActionKeywordMatch(string $normalizedText, string $name, array $definition): int
    {
        $score = 0;
        $terms = array_merge(
            [$name],
            [(string) ($definition['label'] ?? '')],
            [(string) ($definition['description'] ?? '')],
            is_array($definition['keywords'] ?? null) ? $definition['keywords'] : []
        );

        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }

            $candidate = $this->normalizeMatchText($term);
            if ($candidate === '' || mb_strlen($candidate, 'UTF-8') < 2) {
                continue;
            }

            if (str_contains($normalizedText, $candidate)) {
                $score += mb_strlen($candidate, 'UTF-8') >= 6 ? 3 : 2;
            }
        }

        return $score;
    }

    private function normalizeMatchText(string $value): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $normalized = preg_replace('/[\s\-_]+/u', '', $lower);
        return is_string($normalized) ? $normalized : trim($lower);
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

    private function generateConversationId(): string
    {
        try {
            return 'admin-ai-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'admin-ai-' . str_replace('.', '', uniqid('', true));
        }
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

}
