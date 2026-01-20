<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Services\Ai\LlmClientInterface;
use JsonException;
use Psr\Log\LoggerInterface;

class AdminAiIntentService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $navigationTargets = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $quickActions = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $actionDefinitions = [];

    private const ALLOWED_CONTEXT_KEYS = [
        'activeRoute',
        'selectedRecordIds',
        'selectedUserId',
        'locale',
        'timezone',
    ];

    public function __construct(
        private ?LlmClientInterface $client,
        private LoggerInterface $logger,
        array $config = [],
        ?array $commandConfig = null,
        private ?LlmLogService $llmLogService = null
    ) {
        $this->model = (string)($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float)$config['temperature'] : 0.2;
        $this->maxTokens = isset($config['max_tokens']) ? (int)$config['max_tokens'] : 800;
        $this->enabled = $client !== null;
        $this->loadCommandConfig($commandConfig ?? []);
    }

    private function loadCommandConfig(array $commandConfig): void
    {
        $defaults = self::defaultCommandConfig();

        $provided = $commandConfig;
        $navigationTargets = $provided['navigationTargets'] ?? $defaults['navigationTargets'];
        $quickActions = $provided['quickActions'] ?? $defaults['quickActions'];
        $managementActions = $provided['managementActions'] ?? $defaults['managementActions'];

        $this->navigationTargets = $this->indexById($navigationTargets);
        $this->quickActions = $this->indexById($quickActions);
        $this->actionDefinitions = $this->indexById($managementActions, 'name');
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
            'navigationTargets' => [
                [
                    'id' => 'dashboard',
                    'label' => 'Admin Dashboard',
                    'route' => '/admin/dashboard',
                    'description' => 'Overall administration overview with key metrics and quick tasks.',
                    'keywords' => ['dashboard', 'overview', 'summary', '仪表盘', '总览', '首页'],
                ],
                [
                    'id' => 'users',
                    'label' => 'User Management',
                    'route' => '/admin/users',
                    'description' => 'Manage users, roles, points, and account status.',
                    'keywords' => ['user', 'account', '用户', '管理用户', '权限'],
                ],
                [
                    'id' => 'activities',
                    'label' => 'Activity Review',
                    'route' => '/admin/activities',
                    'description' => 'Review and moderate carbon reduction activity submissions.',
                    'keywords' => ['activity', 'review', '碳减排', '审批', '活动'],
                ],
                [
                    'id' => 'products',
                    'label' => 'Reward Store',
                    'route' => '/admin/products',
                    'description' => 'Manage redemption products, inventory and pricing.',
                    'keywords' => ['store', 'product', '奖励', '兑换'],
                ],
                [
                    'id' => 'badges',
                    'label' => 'Badge Management',
                    'route' => '/admin/badges',
                    'description' => 'Create, edit and award achievement badges.',
                    'keywords' => ['badge', '荣誉', '勋章', 'create badge', '颁发'],
                ],
                [
                    'id' => 'avatars',
                    'label' => 'Avatar Library',
                    'route' => '/admin/avatars',
                    'description' => 'Manage avatar assets and default selections.',
                    'keywords' => ['avatar', '头像'],
                ],
                [
                    'id' => 'exchanges',
                    'label' => 'Exchange Orders',
                    'route' => '/admin/exchanges',
                    'description' => 'Review redemption requests and update fulfilment status.',
                    'keywords' => ['order', 'exchange', '兑换申请', '物流'],
                ],
                [
                    'id' => 'broadcast',
                    'label' => 'Broadcast Center',
                    'route' => '/admin/broadcast',
                    'description' => 'Compose and send system broadcast messages.',
                    'keywords' => ['broadcast', '通知', 'announcement', '群发'],
                ],
                [
                    'id' => 'systemLogs',
                    'label' => 'System Logs',
                    'route' => '/admin/system-logs',
                    'description' => 'Inspect audit logs and request traces.',
                    'keywords' => ['log', '日志', '监控', '审计'],
                ],
                [
                    'id' => 'llmUsage',
                    'label' => 'LLM Usage',
                    'route' => '/admin/llm-usage',
                    'description' => 'Monitor LLM quota usage, tokens, and prompt audits.',
                    'keywords' => ['llm', 'ai usage', 'quota', '额度', '调用', '模型', '日志'],
                ],
            ],
            'quickActions' => [
                [
                    'id' => 'search-users',
                    'label' => 'Search users',
                    'description' => 'Focus the user search box for quick lookup.',
                    'routeId' => 'users',
                    'route' => '/admin/users',
                    'mode' => 'shortcut',
                    'query' => ['focus' => 'search'],
                    'keywords' => ['search user', 'find user', '查找用户', '搜用户'],
                ],
                [
                    'id' => 'create-badge',
                    'label' => 'Create new badge',
                    'description' => 'Open the badge creation modal.',
                    'routeId' => 'badges',
                    'route' => '/admin/badges',
                    'mode' => 'shortcut',
                    'query' => ['create' => '1'],
                    'keywords' => ['new badge', 'badge builder', '创建徽章'],
                ],
                [
                    'id' => 'pending-activities',
                    'label' => 'Review pending activities',
                    'description' => 'Filter activity review list to pending items.',
                    'routeId' => 'activities',
                    'route' => '/admin/activities',
                    'mode' => 'shortcut',
                    'query' => ['filter' => 'pending'],
                    'keywords' => ['待审批', 'pending', '审核活动'],
                ],
                [
                    'id' => 'compose-broadcast',
                    'label' => 'Compose broadcast',
                    'description' => 'Open the broadcast composer.',
                    'routeId' => 'broadcast',
                    'route' => '/admin/broadcast',
                    'mode' => 'shortcut',
                    'query' => ['compose' => '1'],
                    'keywords' => ['广播', 'announcement', 'new broadcast'],
                ],
            ],
            'managementActions' => [
                [
                    'name' => 'approve_carbon_records',
                    'label' => 'Approve carbon reduction records',
                    'description' => 'Approve one or more pending carbon reduction activity submissions by record id.',
                    'api' => [
                        'method' => 'PUT',
                        'path' => '/api/v1/admin/activities/review',
                        'payloadTemplate' => [
                            'action' => 'approve',
                            'record_ids' => [],
                            'review_note' => null,
                        ],
                    ],
                    'requires' => ['record_ids'],
                    'contextHints' => ['selectedRecordIds'],
                    'autoExecute' => true,
                ],
                [
                    'name' => 'reject_carbon_records',
                    'label' => 'Reject carbon reduction records',
                    'description' => 'Reject one or more pending carbon reduction records with an optional note.',
                    'api' => [
                        'method' => 'PUT',
                        'path' => '/api/v1/admin/activities/review',
                        'payloadTemplate' => [
                            'action' => 'reject',
                            'record_ids' => [],
                            'review_note' => null,
                        ],
                    ],
                    'requires' => ['record_ids'],
                    'contextHints' => ['selectedRecordIds'],
                    'autoExecute' => true,
                ],
            ],
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getDiagnostics(bool $performConnectivityCheck = false): array
    {
        $diagnostics = [
            'enabled' => $this->enabled,
            'configuration' => [
                'model' => $this->model,
                'temperature' => $this->temperature,
                'maxTokens' => $this->maxTokens,
            ],
            'client' => [
                'available' => $this->client !== null,
                'class' => $this->client ? $this->client::class : null,
            ],
            'commands' => [
                'navigationTargets' => count($this->navigationTargets),
                'quickActions' => count($this->quickActions),
                'managementActions' => count($this->actionDefinitions),
            ],
            'connectivity' => [
                'status' => $this->enabled ? 'not_checked' : 'skipped',
            ],
        ];

        if (!$performConnectivityCheck) {
            return $diagnostics;
        }

        if (!$this->enabled) {
            $diagnostics['connectivity'] = [
                'status' => 'skipped',
                'reason' => 'LLM client not configured',
            ];

            return $diagnostics;
        }

        try {
            $payload = [
                'model' => $this->model,
                'temperature' => 0.0,
                'max_tokens' => 1,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a connectivity probe for diagnostics. Respond with OK.',
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Ping',
                    ],
                ],
            ];

            $response = $this->client->createChatCompletion($payload);

            $diagnostics['connectivity'] = [
                'status' => 'ok',
                'model' => $response['model'] ?? null,
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
                'usage' => $response['usage'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->logger->error('Admin AI diagnostics connectivity check failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $diagnostics['connectivity'] = [
                'status' => 'error',
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ];
        }

        return $diagnostics;
    }

    public function analyzeIntent(string $query, array $context = [], array $logContext = []): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('AI intent service is disabled');
        }

        $tools = $this->buildTools();
        
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->buildMessages($query, $context),
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $startedAt = microtime(true);
        try {
            $rawResponse = $this->client->createChatCompletion($payload);
            $this->logLlmCall($query, $rawResponse, $logContext, $context, $startedAt);
        } catch (\Throwable $e) {
            $this->logger->error('Admin AI intent call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->logLlmFailure($query, $logContext, $context, $startedAt, $e);
            throw new \RuntimeException('LLM_UNAVAILABLE', 0, $e);
        }

        return $this->processResponse($rawResponse, $query);
    }

    private function buildTools(): array
    {
        $tools = [];

        // Tool 1: navigate
        $navEnum = array_keys($this->navigationTargets);
        if (!empty($navEnum)) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'navigate',
                    'description' => 'Navigate to a specific administration page.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'destination' => [
                                'type' => 'string',
                                'enum' => $navEnum,
                                'description' => 'The ID of the page to navigate to.',
                            ],
                            'parameters' => [
                                'type' => 'object',
                                'description' => 'Optional query parameters for the navigation.',
                                'additionalProperties' => true,
                            ],
                        ],
                        'required' => ['destination'],
                    ],
                ],
            ];
        }

        // Tool 2: execute_shortcut
        $shortcutEnum = array_keys($this->quickActions);
        if (!empty($shortcutEnum)) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'execute_shortcut',
                    'description' => 'Execute a predefined quick action or shortcut.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'shortcut_id' => [
                                'type' => 'string',
                                'enum' => $shortcutEnum,
                                'description' => 'The ID of the shortcut to execute.',
                            ],
                        ],
                        'required' => ['shortcut_id'],
                    ],
                ],
            ];
        }

        // Tool 3: manage_records (generic for management actions)
        $actionEnum = array_keys($this->actionDefinitions);
        if (!empty($actionEnum)) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'manage_records',
                    'description' => 'Perform a management action on records.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => [
                                'type' => 'string',
                                'enum' => $actionEnum,
                                'description' => 'The name of the action to perform.',
                            ],
                            'payload' => [
                                'type' => 'object',
                                'description' => 'The payload required for the action (e.g., record_ids, review_note).',
                                'additionalProperties' => true,
                            ],
                        ],
                        'required' => ['action', 'payload'],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private function buildMessages(string $query, array $context): array
    {
        $systemPrompt = $this->buildSystemPrompt();
        $userPayload = $this->buildUserPayload($query, $context);

        return [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userPayload,
            ],
        ];
    }

    private function buildSystemPrompt(): string
    {
        $navDescriptions = [];
        foreach ($this->navigationTargets as $id => $target) {
            $desc = $target['description'] ?? '';
            $navDescriptions[] = "- {$id}: {$desc} (Keywords: " . implode(', ', $target['keywords'] ?? []) . ")";
        }
        
        $shortcutDescriptions = [];
        foreach ($this->quickActions as $id => $action) {
            $desc = $action['description'] ?? '';
            $shortcutDescriptions[] = "- {$id}: {$desc} (Keywords: " . implode(', ', $action['keywords'] ?? []) . ")";
        }
        
        $actionDescriptions = [];
        foreach ($this->actionDefinitions as $name => $def) {
            $desc = $def['description'] ?? '';
            $actionDescriptions[] = "- {$name}: {$desc} (Requires: " . implode(', ', $def['requires'] ?? []) . ")";
        }

        $prompt = "You are CarbonTrack's admin AI command planner. Convert administrator natural language into precise instructions using the provided tools.\n\n";
        
        if (!empty($navDescriptions)) {
            $prompt .= "Navigation Targets:\n" . implode("\n", $navDescriptions) . "\n\n";
        }
        
        if (!empty($shortcutDescriptions)) {
            $prompt .= "Shortcuts:\n" . implode("\n", $shortcutDescriptions) . "\n\n";
        }
        
        if (!empty($actionDescriptions)) {
            $prompt .= "Management Actions:\n" . implode("\n", $actionDescriptions) . "\n\n";
        }
        
        $prompt .= "Rules:\n";
        $prompt .= "- Use 'navigate' for page navigation.\n";
        $prompt .= "- Use 'execute_shortcut' for quick actions.\n";
        $prompt .= "- Use 'manage_records' for data modification actions.\n";
        $prompt .= "- If the user's intent is unclear or not supported, do not call any tool.\n";
        $prompt .= "- Use Chinese labels/reasoning if the user query is Chinese.\n";

        return $prompt;
    }

    private function buildUserPayload(string $query, array $context): string
    {
        $filteredContext = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT_KEYS));

        return json_encode([
            'query' => $query,
            'context' => $filteredContext,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function logLlmCall(string $prompt, array $rawResponse, array $logContext, array $context, float $startedAt): void
    {
        if (!$this->llmLogService) {
            return;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000.0;
        $responseId = $rawResponse['id'] ?? ($rawResponse['metadata']['request_id'] ?? null);
        $contextPayload = $this->filterContextForLogging($context);

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? null,
            'model' => $rawResponse['model'] ?? $this->model,
            'prompt' => $prompt,
            'response_raw' => $rawResponse,
            'response_id' => $responseId,
            'status' => 'success',
            'error_message' => null,
            'usage' => $rawResponse['usage'] ?? null,
            'latency_ms' => round($durationMs, 2),
            'context' => $contextPayload ?: null,
        ]);
    }

    private function logLlmFailure(string $prompt, array $logContext, array $context, float $startedAt, \Throwable $error): void
    {
        if (!$this->llmLogService) {
            return;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000.0;
        $contextPayload = $this->filterContextForLogging($context);

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? null,
            'model' => $this->model,
            'prompt' => $prompt,
            'response_raw' => null,
            'response_id' => null,
            'status' => 'failed',
            'error_message' => $error->getMessage(),
            'usage' => null,
            'latency_ms' => round($durationMs, 2),
            'context' => $contextPayload ?: null,
        ]);
    }

    private function filterContextForLogging(array $context): array
    {
        if (empty($context)) {
            return [];
        }

        $filtered = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT_KEYS));
        if (isset($filtered['selectedRecordIds']) && is_array($filtered['selectedRecordIds'])) {
            $ids = array_values(array_filter($filtered['selectedRecordIds'], fn ($item) => $item !== null && $item !== ''));
            $filtered['selectedRecordIds'] = array_slice($ids, 0, 20);
            if (count($ids) > 20) {
                $filtered['selectedRecordIds_truncated'] = true;
                $filtered['selectedRecordIds_total'] = count($ids);
            }
        }

        return $filtered;
    }

    private function extractIntentFromContent(?string $content, string $originalQuery, array $rawResponse): ?array
    {
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['intent']) || !is_array($decoded['intent'])) {
            return null;
        }

        $intentData = $decoded['intent'];
        $intentType = $intentData['type'] ?? null;
        $intent = null;

        switch ($intentType) {
            case 'navigate':
                $destination = $intentData['target']['routeId'] ?? ($intentData['target']['route'] ?? null);
                $intent = $this->createNavigationIntent([
                    'destination' => $destination,
                    'parameters' => $intentData['target']['query'] ?? [],
                ]);
                if ($intent === null) {
                    return $this->fallbackIntent($rawResponse);
                }
                break;
            case 'quick_action':
                $shortcutId = $intentData['target']['routeId'] ?? ($intentData['target']['id'] ?? null);
                $intent = $this->createShortcutIntent([
                    'shortcut_id' => $shortcutId,
                ]);
                if ($intent === null) {
                    return $this->fallbackIntent($rawResponse);
                }
                break;
            case 'action':
                $actionName = $intentData['action']['name'] ?? null;
                $payload = $intentData['action']['api']['payload'] ?? [];
                $intent = $this->createManagementIntent([
                    'action' => $actionName,
                    'payload' => $payload,
                ]);
                if ($intent === null) {
                    return $this->fallbackIntent($rawResponse);
                }
                break;
            case 'fallback':
                $heuristic = $this->guessNavigationIntent($originalQuery);
                if ($heuristic) {
                    return [
                        'intent' => $heuristic,
                        'alternatives' => [],
                        'metadata' => $this->extractMetadata($rawResponse),
                    ];
                }
                return $this->fallbackIntent($rawResponse);
        }

        if ($intent === null) {
            return null;
        }

        return [
            'intent' => $intent,
            'alternatives' => [],
            'metadata' => $this->extractMetadata($rawResponse),
        ];
    }

    private function processResponse(array $rawResponse, string $originalQuery): array
    {
        $choice = $rawResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];
        $content = $message['content'] ?? null;

        if ($contentIntent = $this->extractIntentFromContent($content, $originalQuery, $rawResponse)) {
            return $contentIntent;
        }

        if (empty($toolCalls)) {
            // Try to parse JSON from content if tool_calls is empty
            if (is_string($content) && $content !== '') {
                $jsonContent = null;
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                    $jsonContent = $matches[1];
                } elseif (preg_match('/^\s*\{.*\}\s*$/s', $content) || stripos($content, '{') !== false) {
                    $start = strpos($content, '{');
                    $end = strrpos($content, '}');
                    if ($start !== false && $end !== false && $end > $start) {
                        $jsonContent = substr($content, $start, $end - $start + 1);
                    }
                }

                if ($jsonContent !== null) {
                    try {
                        $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($data) && isset($data['function'], $data['parameters'])) {
                            $func = $data['function'];
                            $args = $data['parameters'];
                            $intent = null;
                            if ($func === 'navigate') $intent = $this->createNavigationIntent($args);
                            elseif ($func === 'execute_shortcut') $intent = $this->createShortcutIntent($args);
                            elseif ($func === 'manage_records') $intent = $this->createManagementIntent($args);

                            if ($intent) {
                                return [
                                    'intent' => $intent,
                                    'alternatives' => [],
                                    'metadata' => $this->extractMetadata($rawResponse),
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore json parse error
                    }
                }
            }

            // Fallback to heuristic if no tool called
            $heuristic = $this->guessNavigationIntent($originalQuery);
            if ($heuristic) {
                return [
                    'intent' => $heuristic,
                    'alternatives' => [],
                    'metadata' => $this->extractMetadata($rawResponse),
                ];
            }
            return $this->fallbackIntent($rawResponse);
        }

        // Process the first tool call (we assume single intent for now)
        $toolCall = $toolCalls[0];
        $functionName = $toolCall['function']['name'] ?? '';
        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

        $intent = null;

        switch ($functionName) {
            case 'navigate':
                $intent = $this->createNavigationIntent($arguments);
                break;
            case 'execute_shortcut':
                $intent = $this->createShortcutIntent($arguments);
                break;
            case 'manage_records':
                $intent = $this->createManagementIntent($arguments);
                break;
        }

        if (!$intent) {
             return $this->fallbackIntent($rawResponse);
        }

        return [
            'intent' => $intent,
            'alternatives' => [], // We could potentially ask for multiple tool calls for alternatives
            'metadata' => $this->extractMetadata($rawResponse),
        ];
    }

    private function createNavigationIntent(array $args): ?array
    {
        $destination = $args['destination'] ?? null;
        if (!$destination || !isset($this->navigationTargets[$destination])) {
            return null;
        }

        $target = $this->navigationTargets[$destination];
        $query = $args['parameters'] ?? [];

        return [
            'type' => 'navigate',
            'label' => $target['label'],
            'confidence' => 0.9,
            'reasoning' => 'AI determined navigation intent via tool call.',
            'target' => [
                'routeId' => $destination,
                'route' => $target['route'],
                'mode' => 'navigation',
                'query' => $query,
            ],
            'missing' => [],
        ];
    }

    private function createShortcutIntent(array $args): ?array
    {
        $shortcutId = $args['shortcut_id'] ?? null;
        if (!$shortcutId || !isset($this->quickActions[$shortcutId])) {
            return null;
        }

        $action = $this->quickActions[$shortcutId];

        return [
            'type' => 'quick_action',
            'label' => $action['label'],
            'confidence' => 0.9,
            'reasoning' => 'AI determined shortcut intent via tool call.',
            'target' => [
                'routeId' => $action['routeId'] ?? $shortcutId,
                'route' => $action['route'] ?? null,
                'mode' => $action['mode'] ?? 'shortcut',
                'query' => $action['query'] ?? [],
            ],
            'missing' => [],
        ];
    }

    private function createManagementIntent(array $args): ?array
    {
        $actionName = $args['action'] ?? null;
        if (!$actionName || !isset($this->actionDefinitions[$actionName])) {
            return null;
        }

        $definition = $this->actionDefinitions[$actionName];
        $payload = $args['payload'] ?? [];

        // Merge with template
        $apiDefinition = $definition['api'] ?? [];
        $payloadTemplate = $apiDefinition['payloadTemplate'] ?? [];
        $finalPayload = $this->mergePayloadTemplate($payloadTemplate, $payload);

        // Check requirements
        $requires = $definition['requires'] ?? [];
        $missing = $this->resolveMissingRequirements($requires, $finalPayload);

        return [
            'type' => 'action',
            'label' => $definition['label'],
            'confidence' => 0.9,
            'reasoning' => 'AI determined management action via tool call.',
            'action' => [
                'name' => $actionName,
                'summary' => $definition['label'],
                'api' => [
                    'method' => $apiDefinition['method'] ?? 'POST',
                    'path' => $apiDefinition['path'] ?? '',
                    'payload' => $finalPayload,
                ],
                'autoExecute' => $definition['autoExecute'] ?? false,
                'requires' => $requires,
            ],
            'missing' => $missing,
        ];
    }

    private function extractMetadata(array $rawResponse): array
    {
        return [
            'model' => $rawResponse['model'] ?? $this->model,
            'usage' => $rawResponse['usage'] ?? null,
            'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? null,
        ];
    }

    private function mergePayloadTemplate(array $template, array $payload): array
    {
        $result = $template;
        foreach ($payload as $key => $value) {
            $result[$key] = $value;
        }
        return $result;
    }

    private function resolveMissingRequirements(array $requirements, array $payload): array
    {
        $missing = [];
        foreach ($requirements as $field) {
            $value = $payload[$field] ?? null;
            $isMissing = false;
            if (is_array($value)) {
                $isMissing = count(array_filter($value, fn ($item) => $item !== null && $item !== '')) === 0;
            } else {
                $isMissing = $value === null || $value === '' || $value === [];
            }

            if ($isMissing) {
                $missing[] = [
                    'field' => $field,
                    'description' => sprintf('Provide a value for %s.', $field),
                ];
            }
        }
        return $missing;
    }

    private function fallbackIntent(array $rawResponse = []): array
    {
        return [
            'intent' => [
                'type' => 'fallback',
                'label' => '未能理解的指令',
                'confidence' => 0.0,
                'reasoning' => '无法从输入中提取明确的管理指令，请改用关键字搜索或再具体一些。',
                'missing' => [],
            ],
            'alternatives' => [],
            'metadata' => [
                'model' => $this->model,
                'usage' => $rawResponse['usage'] ?? null,
                'finish_reason' => 'fallback',
            ],
        ];
    }

    private function guessNavigationIntent(string $query): ?array
    {
        $normalizedQuery = trim(mb_strtolower($query));
        if ($normalizedQuery === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $matchedKeywords = [];

        foreach ($this->navigationTargets as $id => $definition) {
            $match = $this->computeDefinitionMatch($normalizedQuery, $definition);
            if ($match['score'] > $bestScore) {
                $bestScore = $match['score'];
                $best = [
                    'type' => 'navigate',
                    'definition' => $definition,
                    'routeId' => is_string($id) ? $id : ($definition['id'] ?? null),
                ];
                $matchedKeywords = $match['keywords'];
            }
        }

        foreach ($this->quickActions as $id => $definition) {
            $match = $this->computeDefinitionMatch($normalizedQuery, $definition);
            if ($match['score'] > $bestScore) {
                $bestScore = $match['score'];
                $best = [
                    'type' => 'quick_action',
                    'definition' => $definition,
                    'routeId' => is_string($id) ? $id : ($definition['id'] ?? null),
                ];
                $matchedKeywords = $match['keywords'];
            }
        }

        if ($best === null || $bestScore === 0) {
            return null;
        }

        $definition = $best['definition'];
        $route = $definition['route'] ?? null;
        if (!is_string($route) || $route === '') {
            return null;
        }

        $mode = $best['type'] === 'quick_action' ? ($definition['mode'] ?? 'shortcut') : 'navigation';
        $queryParams = [];
        if (isset($definition['query']) && is_array($definition['query'])) {
            $queryParams = $definition['query'];
        }

        $confidence = min(0.9, 0.45 + 0.12 * min($bestScore, 6));
        $reasoning = 'Matched keywords: ' . implode(', ', array_unique($matchedKeywords));

        return [
            'type' => $best['type'],
            'label' => $definition['label'] ?? ($best['routeId'] ?? 'Navigate'),
            'confidence' => round($confidence, 2),
            'reasoning' => $reasoning,
            'target' => [
                'routeId' => $best['routeId'],
                'route' => $route,
                'mode' => $mode,
                'query' => $queryParams,
            ],
            'missing' => [],
        ];
    }

    private function computeDefinitionMatch(string $normalizedQuery, array $definition): array
    {
        $score = 0;
        $matches = [];

        foreach ($this->collectDefinitionKeywords($definition) as $keyword) {
            $keyword = trim(mb_strtolower($keyword));
            if ($keyword === '') {
                continue;
            }
            if (mb_strpos($normalizedQuery, $keyword) !== false) {
                $score += max(1, (int) floor(mb_strlen($keyword) / 4));
                $matches[] = $keyword;
            }
        }

        return ['score' => $score, 'keywords' => $matches];
    }

    private function collectDefinitionKeywords(array $definition): array
    {
        $keywords = [];

        if (!empty($definition['keywords']) && is_array($definition['keywords'])) {
            foreach ($definition['keywords'] as $keyword) {
                if (is_string($keyword)) {
                    $keywords[] = $keyword;
                }
            }
        }

        foreach (['label', 'description'] as $field) {
            if (!empty($definition[$field]) && is_string($definition[$field])) {
                $keywords[] = $definition[$field];
            }
        }

        if (!empty($definition['route']) && is_string($definition['route'])) {
            $keywords[] = str_replace(['/admin/', '/'], ' ', $definition['route']);
        }

        return $keywords;
    }

    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $enabled;
}

