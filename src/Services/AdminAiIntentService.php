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
        private array $config = [],
        ?array $commandConfig = null
    ) {
        $this->model = (string)($config['model'] ?? 'gpt-4o-mini');
        $this->temperature = isset($config['temperature']) ? (float)$config['temperature'] : 0.2;
        $this->maxTokens = isset($config['max_tokens']) ? (int)$config['max_tokens'] : 800;
        $this->enabled = $client !== null;
        $this->loadCommandConfig($commandConfig ?? []);
    }

    private function loadCommandConfig(array $commandConfig): void
    {
        $defaults = self::defaultCommandConfig();

        $provided = $commandConfig;
        $provided = $commandConfig;
        $navigationTargets = $provided['navigationTargets'] ?? $defaults['navigationTargets'];
        $quickActions = $provided['quickActions'] ?? $defaults['quickActions'];
        $managementActions = $provided['managementActions'] ?? $defaults['managementActions'];

        $this->navigationTargets = $this->indexById($navigationTargets);
        $this->quickActions = $this->indexById($quickActions);
        $this->actionDefinitions = $this->indexById($managementActions, 'name');
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
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

    /**
     * @return array{navigationTargets: array<int,array<string,mixed>>, quickActions: array<int,array<string,mixed>>, managementActions: array<int,array<string,mixed>>}
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeIntent(string $query, array $context = []): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('AI intent service is disabled');
        }

        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->buildMessages($query, $context),
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $rawResponse = $this->client->createChatCompletion($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Admin AI intent call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('LLM_UNAVAILABLE', 0, $e);
        }

        $content = $rawResponse['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            $this->logger->warning('Admin AI intent returned empty content', [
                'raw' => $rawResponse,
            ]);
            return $this->fallbackIntent($query);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('Admin AI intent JSON decode failed', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            return $this->fallbackIntent($query);
        }

        return $this->normalizeResult($decoded, $rawResponse, $query);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,string>>
     */
    private function buildMessages(string $query, array $context): array
    {
        $systemPrompt = $this->buildSystemPrompt();
        $fewShot = $this->buildFewShotMessages();
        $userPayload = $this->buildUserPayload($query, $context);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];

        foreach ($fewShot as $message) {
            $messages[] = $message;
        }

        $messages[] = [
            'role' => 'user',
            'content' => $userPayload,
        ];

        return $messages;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function buildFewShotMessages(): array
    {
        $exampleContext = json_encode([
            'query' => '帮我审批ID为 182 和 196 的碳减排活动',
            'context' => [
                'selectedRecordIds' => [150],
                'activeRoute' => '/admin/activities',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $exampleResponse = json_encode([
            'intent' => [
                'type' => 'action',
                'label' => 'Approve 2 carbon reduction records',
                'confidence' => 0.83,
                'reasoning' => 'The user explicitly asks to approve activities 182 and 196.',
                'action' => [
                    'name' => 'approve_carbon_records',
                    'summary' => 'Approve records 182 and 196',
                    'api' => [
                        'method' => 'PUT',
                        'path' => '/api/v1/admin/activities/review',
                        'payload' => [
                            'action' => 'approve',
                            'record_ids' => [182, 196],
                            'review_note' => null,
                        ],
                    ],
                    'autoExecute' => true,
                ],
                'missing' => [],
            ],
            'alternatives' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            [
                'role' => 'user',
                'content' => $exampleContext,
            ],
            [
                'role' => 'assistant',
                'content' => $exampleResponse,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildUserPayload(string $query, array $context): string
    {
        $filteredContext = array_intersect_key($context, array_flip(self::ALLOWED_CONTEXT_KEYS));

        return json_encode([
            'query' => $query,
            'context' => $filteredContext,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function buildSystemPrompt(): string
    {
        $navigationTargets = array_values(array_map(
            fn (array $target) => [
                'id' => $target['id'] ?? '',
                'label' => $target['label'] ?? ($target['id'] ?? ''),
                'route' => $target['route'] ?? null,
                'description' => $target['description'] ?? null,
                'keywords' => array_values((array)($target['keywords'] ?? [])),
            ],
            $this->navigationTargets
        ));

        $quickActions = array_map(
            function (array $action): array {
                $keywords = array_values((array)($action['keywords'] ?? []));
                $route = $action['route'] ?? null;
                $routeId = $action['routeId'] ?? ($action['id'] ?? '');

                if ($route) {
                    $keywords[] = $route;
                }
                if ($routeId) {
                    $keywords[] = $routeId;
                }

                if (isset($action['query']) && is_array($action['query'])) {
                    foreach ($action['query'] as $key => $value) {
                        if (is_scalar($value)) {
                            $keywords[] = sprintf('%s=%s', $key, (string) $value);
                        }
                    }
                }

                return [
                    'id' => $action['id'] ?? '',
                    'label' => $action['label'] ?? ($action['id'] ?? ''),
                    'routeId' => $routeId,
                    'route' => $route,
                    'mode' => $action['mode'] ?? 'navigation',
                    'query' => is_array($action['query'] ?? null) ? $action['query'] : [],
                    'description' => $action['description'] ?? null,
                    'keywords' => array_values(array_unique($keywords)),
                ];
            },
            array_values($this->quickActions)
        );

        $managementActions = array_values(array_map(
            fn (array $definition) => [
                'name' => $definition['name'] ?? '',
                'label' => $definition['label'] ?? ($definition['name'] ?? ''),
                'description' => $definition['description'] ?? null,
                'api' => $definition['api'] ?? [],
                'requires' => array_values((array)($definition['requires'] ?? [])),
                'contextHints' => array_values((array)($definition['contextHints'] ?? [])),
                'autoExecute' => (bool)($definition['autoExecute'] ?? false),
                'keywords' => array_values((array)($definition['keywords'] ?? [])),
            ],
            $this->actionDefinitions
        ));

        $responseSchema = [
            'intent' => [
                'type' => 'navigate|quick_action|action|fallback',
                'label' => 'human readable title',
                'confidence' => 'number 0-1',
                'reasoning' => 'short explanation',
                'target' => [
                    'routeId' => 'for navigation/quick actions',
                    'route' => 'path starting with /admin',
                    'query' => 'optional query parameters',
                    'mode' => 'navigation|shortcut',
                ],
                'action' => [
                    'name' => 'matches managementActions.name',
                    'summary' => 'human summary',
                    'api' => [
                        'method' => 'HTTP verb',
                        'path' => 'full API path beginning with /api/v1',
                        'payload' => 'JSON payload ready for execution',
                    ],
                    'autoExecute' => 'boolean default false',
                ],
                'missing' => [
                    [
                        'field' => 'record_ids',
                        'description' => 'explain what is needed',
                    ],
                ],
            ],
            'alternatives' => 'optional array of additional intents following the same shape',
        ];

        $capabilities = [
            'navigationTargets' => $navigationTargets,
            'quickActions' => array_values($quickActions),
            'managementActions' => $managementActions,
            'responseSchema' => $responseSchema,
        ];

        $capabilityJson = json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
You are CarbonTrack's admin AI command planner. Convert administrator natural language into precise instructions.

Rules:
- Only use navigation targets, quick actions, and management actions exactly as defined.
- Prefer navigation or quick actions when the user wants to open a page or UI workflow.
- For management actions, fill payload fields using provided context. If required parameters are missing, leave them empty and describe the missing information.
- Do not invent endpoints or parameters outside the provided list.
- Always respond with a single JSON object matching the responseSchema.
- Use Chinese labels if the user query is Chinese, otherwise keep English.
- Keep reasoning short.

Capabilities:
{$capabilityJson}
PROMPT;
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $rawResponse
     * @return array<string,mixed>
     */
    private function normalizeResult(array $decoded, array $rawResponse): array
    {
        $intent = $decoded['intent'] ?? null;
        if (!is_array($intent)) {
            return $this->fallbackIntent($decoded['query'] ?? '');
        }

        $primary = $this->normalizeIntent($intent);
        if (($primary['type'] ?? 'fallback') === 'fallback') {
            $heuristic = $this->guessNavigationIntent($originalQuery);
            if ($heuristic !== null) {
                $primary = $heuristic;
            }
        }

        $alternatives = [];
        if (!empty($decoded['alternatives']) && is_array($decoded['alternatives'])) {
            foreach ($decoded['alternatives'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $alternatives[] = $this->normalizeIntent($candidate);
            }
        }

        $result = [
            'intent' => $primary,
            'alternatives' => $alternatives,
            'metadata' => [
                'model' => $rawResponse['model'] ?? $this->model,
                'usage' => $rawResponse['usage'] ?? null,
                'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? null,
            ],
        ];
        if ($result['intent']['type'] === 'fallback') {
            $heuristic = $this->guessNavigationIntent($decoded['query'] ?? $originalQuery);
            if ($heuristic !== null) {
                $result['intent'] = $heuristic;
            }
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    private function normalizeIntent(array $intent): array
    {
        $type = is_string($intent['type'] ?? null) ? strtolower((string)$intent['type']) : 'fallback';
        $confidence = $this->normalizeConfidence($intent['confidence'] ?? null);
        $label = is_string($intent['label'] ?? null) ? trim($intent['label']) : 'AI suggestion';
        $reasoning = is_string($intent['reasoning'] ?? null) ? trim($intent['reasoning']) : null;

        // Attempt heuristic mapping when model returns a raw navigation target string, e.g. "activities"
        if ($type === 'fallback' && isset($intent['target']) && is_array($intent['target'])) {
            $targetRouteId = $intent['target']['routeId'] ?? $intent['target']['route'] ?? null;
            if (is_string($targetRouteId) && $targetRouteId !== '') {
                $matches = $this->matchRouteHeuristically($targetRouteId);
                if ($matches !== null) {
                    $intent['type'] = $matches['type'];
                    $intent['target'] = [
                        'routeId' => $matches['id'],
                        'route' => $matches['route'],
                        'query' => $matches['query'],
                    ];
                    $type = $intent['type'];
                }
            }
        }

        $normalized = [
            'type' => $type,
            'label' => $label,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'missing' => [],
        ];

        if ($type === 'navigate' || $type === 'quick_action') {
            $normalized = array_merge($normalized, $this->normalizeNavigationIntent($intent, $type));
        } elseif ($type === 'action') {
            $normalized = array_merge($normalized, $this->normalizeActionIntent($intent));
        } else {
            $normalized['type'] = 'fallback';
            $normalized['reasoning'] = $reasoning ?? 'Unable to confidently map the instruction to a known command.';
        }

        return $normalized;
    }

    /**
     * @return array{type:string,id:string,route:?string,query:array<string,string>}|null
     */
    private function matchRouteHeuristically(string $raw): ?array
    {
        $needle = strtolower(trim($raw));
        if ($needle === '') {
            return null;
        }

        $candidates = [];

        foreach ($this->navigationTargets as $id => $target) {
            $score = $this->scoreKeywords($needle, $target['keywords'] ?? [], $target['label'] ?? '', $target['route'] ?? '', $id);
            if ($score > 0) {
                $candidates[] = [
                    'type' => 'navigate',
                    'id' => $id,
                    'route' => $target['route'] ?? null,
                    'query' => [],
                    'score' => $score,
                ];
            }
        }

        foreach ($this->quickActions as $id => $action) {
            $score = $this->scoreKeywords($needle, $action['keywords'] ?? [], $action['label'] ?? '', $action['route'] ?? '', $id);
            if ($score > 0) {
                $candidates[] = [
                    'type' => 'quick_action',
                    'id' => $id,
                    'route' => $action['route'] ?? null,
                    'query' => is_array($action['query'] ?? null) ? array_map('strval', $action['query']) : [],
                    'score' => $score,
                ];
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $best = $candidates[0];
        if ($best['score'] < 0.4) {
            return null;
        }

        return [
            'type' => $best['type'],
            'id' => $best['id'],
            'route' => $best['route'],
            'query' => $best['query'],
        ];
    }

    /**
     * @param array<int,string> $keywords
     */
    private function scoreKeywords(string $needle, array $keywords, string $label, string $route, string $id): float
    {
        $pool = array_filter(array_map('strtolower', array_merge($keywords, [$label, $route, $id])));
        if (empty($pool)) {
            return 0.0;
        }

        $best = 0.0;
        foreach ($pool as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if ($needle === $candidate) {
                return 1.0;
            }
            if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                $best = max($best, 0.8);
                continue;
            }
            $similarity = 0.0;
            similar_text($needle, $candidate, $similarity);
            $best = max($best, $similarity / 100);
        }

        return $best;
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    private function normalizeNavigationIntent(array $intent, string $type): array
    {
        $target = $intent['target'] ?? [];
        $routeId = is_string($target['routeId'] ?? null) ? $target['routeId'] : null;

        $definition = null;
        $mode = 'navigation';

        if ($routeId && isset($this->navigationTargets[$routeId])) {
            $definition = $this->navigationTargets[$routeId];
        } elseif ($routeId && isset($this->quickActions[$routeId])) {
            $definition = $this->quickActions[$routeId];
            $mode = $definition['mode'] ?? 'shortcut';
        } elseif ($routeId) {
            foreach ($this->quickActions as $quick) {
                if (($quick['routeId'] ?? null) === $routeId) {
                    $definition = $quick;
                    $mode = $quick['mode'] ?? 'shortcut';
                    $routeId = $quick['id'] ?? $routeId;
                    break;
                }
            }
            if ($definition === null) {
                foreach ($this->navigationTargets as $nav) {
                    if (($nav['id'] ?? null) === $routeId) {
                        $definition = $nav;
                        break;
                    }
                }
            }
        }

        if ($definition === null) {
            $route = is_string($target['route'] ?? null) ? $target['route'] : null;
            if ($route) {
                foreach ($this->navigationTargets as $nav) {
                    if (($nav['route'] ?? null) === $route) {
                        $definition = $nav;
                        $routeId = $nav['id'] ?? $routeId;
                        break;
                    }
                }
                if ($definition === null) {
                    foreach ($this->quickActions as $quick) {
                        if (($quick['route'] ?? null) === $route) {
                            $definition = $quick;
                            $mode = $quick['mode'] ?? 'shortcut';
                            $routeId = $quick['id'] ?? $routeId;
                            break;
                        }
                    }
                }
            }
        }

        if ($definition === null) {
            return [
                'type' => 'fallback',
                'reasoning' => 'Target route is not part of the allowed navigation set.',
                'target' => null,
                'missing' => [],
            ];
        }

        $query = [];
        if (isset($target['query']) && is_array($target['query'])) {
            foreach ($target['query'] as $key => $value) {
                if (is_scalar($value)) {
                    $query[(string)$key] = (string)$value;
                }
            }
        } elseif (isset($definition['query']) && is_array($definition['query'])) {
            $query = $definition['query'];
        }

        return [
            'target' => [
                'routeId' => $routeId ?? ($definition['id'] ?? null),
                'route' => $definition['route'] ?? null,
                'mode' => $mode,
                'query' => $query,
            ],
            'missing' => [],
        ];
    }


    /**
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    private function normalizeActionIntent(array $intent): array
    {
        $action = $intent['action'] ?? [];
        $name = is_string($action['name'] ?? null) ? $action['name'] : null;
        if (!$name || !isset($this->actionDefinitions[$name])) {
            return [
                'type' => 'fallback',
                'reasoning' => 'Requested action is not available.',
                'action' => null,
                'missing' => [],
            ];
        }

        $definition = $this->actionDefinitions[$name];

        $apiDefinition = $definition['api'] ?? [];
        $payloadTemplate = $apiDefinition['payloadTemplate'] ?? [];

        $api = $action['api'] ?? [];
        $payload = $api['payload'] ?? null;
        if (!is_array($payload)) {
            $payload = [];
        }

        $payload = $this->mergePayloadTemplate($payloadTemplate, $payload);

        $requires = is_array($definition['requires'] ?? null) ? $definition['requires'] : [];
        $missing = $this->resolveMissingRequirements($requires, $payload);

        $summary = is_string($action['summary'] ?? null)
            ? trim($action['summary'])
            : ($definition['label'] ?? $name);

        $autoExecute = isset($action['autoExecute'])
            ? (bool)$action['autoExecute']
            : (bool)($definition['autoExecute'] ?? false);

        $method = $apiDefinition['method'] ?? 'POST';
        $path = $apiDefinition['path'] ?? '';

        return [
            'action' => [
                'name' => $definition['name'] ?? $name,
                'summary' => $summary,
                'api' => [
                    'method' => $method,
                    'path' => $path,
                    'payload' => $payload,
                ],
                'autoExecute' => $autoExecute,
                'requires' => $requires,
            ],
            'missing' => $missing,
        ];
    }


    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function mergePayloadTemplate(array $template, array $payload): array
    {
        $result = $template;
        foreach ($payload as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<int,string> $requirements
     * @param array<string,mixed> $payload
     * @return array<int,array{field:string,description:string}>
     */
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

    private function normalizeConfidence(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.5;
        }

        $confidence = (float)$value;
        if ($confidence < 0) {
            return 0.0;
        }

        if ($confidence > 1) {
            return 1.0;
        }

        return round($confidence, 2);
    }

    /**
     * @return array<string,mixed>
     */
    private function fallbackIntent(string $query): array
    {
        return [
            'intent' => [
                'type' => 'fallback',
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

                    /**
                     * @return array{score:int,keywords:array<int,string>}
                     */
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

                    /**
                     * @return array<int,string>
                     */
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
                'label' => '未能理解的指令',
                'confidence' => 0.0,
                'reasoning' => '无法从输入中提取明确的管理指令，请改用关键字搜索或再具体一些。',
                'missing' => [],
            ],
            'alternatives' => [],
            'metadata' => [
                'model' => $this->model,
                'usage' => null,
                'finish_reason' => 'fallback',
            ],
        ];
    }

    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $enabled;
}

