<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Services\AdminAiAgentService;
use CarbonTrack\Services\AdminAnnouncementAiException;
use CarbonTrack\Services\AdminAnnouncementAiService;
use CarbonTrack\Services\AdminAnnouncementAiUnavailableException;
use CarbonTrack\Services\AdminAiCommandRepository;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminAiController
{
    public function __construct(
        private AuthService $authService,
        private AdminAiIntentService $intentService,
        private AdminAnnouncementAiService $announcementAiService,
        private AdminAiCommandRepository $commandRepository,
        private AuditLogService $auditLogService,
        private ?ErrorLogService $errorLogService = null,
        private ?LoggerInterface $logger = null,
        private ?AdminAiAgentService $agentService = null
    ) {
    }

    public function chat(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            if ($this->agentService === null || !$this->agentService->isEnabled()) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI assistant is not configured. Please set LLM_API_KEY on the server.',
                    'code' => 'AI_DISABLED',
                ], 503);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $message = isset($data['message']) ? trim((string) $data['message']) : null;
            $conversationId = isset($data['conversation_id']) ? trim((string) $data['conversation_id']) : null;
            $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
            $decision = isset($data['decision']) && is_array($data['decision']) ? $data['decision'] : null;
            $source = isset($data['source']) && is_string($data['source']) ? trim($data['source']) : null;
            if (($source === null || $source === '') && isset($context['activeRoute']) && is_string($context['activeRoute'])) {
                $source = trim($context['activeRoute']);
            }

            if (($message === null || $message === '') && $decision === null) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Either message or decision is required.',
                    'code' => 'INVALID_INPUT',
                ], 422);
            }

            $result = $this->agentService->chat($conversationId, $message, $context, $decision, [
                'request_id' => $request->getAttribute('request_id'),
                'actor_type' => 'admin',
                'actor_id' => $user['id'] ?? null,
                'source' => $source ?? $request->getUri()->getPath(),
            ]);

            $this->logAdminAudit('admin_ai_chat_completed', $user, $request, [
                'conversation_id' => $result['conversation_id'] ?? null,
                'data' => [
                    'has_decision' => $decision !== null,
                    'source' => $source ?? $request->getUri()->getPath(),
                ],
            ]);

            return $this->json($response, $result);
        } catch (\InvalidArgumentException $exception) {
            return $this->json($response, [
                'success' => false,
                'error' => $exception->getMessage(),
                'code' => 'INVALID_INPUT',
            ], 422);
        } catch (\RuntimeException $runtimeException) {
            if ($runtimeException->getMessage() === 'LLM_UNAVAILABLE') {
                $this->logException($runtimeException, $request, 'AdminAI chat unavailable');
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI provider is temporarily unavailable. Please try again later.',
                    'code' => 'AI_UNAVAILABLE',
                ], 503);
            }

            if ($runtimeException->getMessage() === 'PROPOSAL_NOT_FOUND') {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Proposal not found for this conversation.',
                    'code' => 'PROPOSAL_NOT_FOUND',
                ], 404);
            }

            $this->logException($runtimeException, $request, 'AdminAI chat runtime error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to process the admin AI request',
                'code' => 'AI_CHAT_ERROR',
            ], 500);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI chat unexpected error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Unexpected server error',
                'code' => 'AI_CHAT_SERVER_ERROR',
            ], 500);
        }
    }

    public function workspace(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            $config = $this->commandRepository->getConfig();
            $agentConfig = isset($config['agent']) && is_array($config['agent']) ? $config['agent'] : [];
            $adminId = isset($user['id']) && is_numeric((string) $user['id']) ? (int) $user['id'] : null;
            $recentConversations = $this->agentService !== null
                ? $this->agentService->listConversations([
                    'limit' => 8,
                    'admin_id' => $adminId,
                ])
                : [];

            $data = [
                'assistant' => [
                    'chat_enabled' => $this->agentService?->isEnabled() ?? false,
                    'intent_enabled' => $this->intentService->isEnabled(),
                    'default_confirmation_policy' => isset($agentConfig['default_confirmation_policy']) && is_string($agentConfig['default_confirmation_policy'])
                        ? $agentConfig['default_confirmation_policy']
                        : null,
                    'max_history_messages' => isset($agentConfig['max_history_messages']) ? (int) $agentConfig['max_history_messages'] : null,
                    'max_auto_read_steps' => isset($agentConfig['max_auto_read_steps']) ? (int) $agentConfig['max_auto_read_steps'] : null,
                    'system_behavior' => array_values(array_filter(
                        isset($agentConfig['systemBehavior']) && is_array($agentConfig['systemBehavior']) ? $agentConfig['systemBehavior'] : [],
                        static fn ($item): bool => is_string($item) && trim($item) !== ''
                    )),
                    'commands_fingerprint' => $this->commandRepository->getFingerprint(),
                    'commands_source' => $this->commandRepository->getActivePath(),
                    'commands_last_modified' => $this->commandRepository->getLastModified(),
                ],
                'navigation_targets' => $this->normalizeWorkspaceNavigationTargets($config['navigationTargets'] ?? []),
                'quick_actions' => $this->normalizeWorkspaceQuickActions($config['quickActions'] ?? []),
                'management_actions' => $this->normalizeWorkspaceManagementActions($config['managementActions'] ?? []),
                'starter_prompts' => $this->buildWorkspaceStarterPrompts($config['managementActions'] ?? []),
                'recent_conversations' => $recentConversations,
            ];

            $this->logAdminAudit('admin_ai_workspace_viewed', $user, $request, [
                'data' => [
                    'recent_conversation_count' => count($recentConversations),
                    'chat_enabled' => $data['assistant']['chat_enabled'],
                    'intent_enabled' => $data['assistant']['intent_enabled'],
                ],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI workspace error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to load the admin AI workspace',
                'code' => 'AI_WORKSPACE_ERROR',
            ], 500);
        }
    }

    public function conversations(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            if ($this->agentService === null) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI assistant is not configured.',
                    'code' => 'AI_DISABLED',
                ], 503);
            }

            $query = $request->getQueryParams();
            $items = $this->agentService->listConversations([
                'limit' => $query['limit'] ?? 20,
                'actor_id' => $query['actor_id'] ?? null,
                'admin_id' => $query['admin_id'] ?? null,
                'status' => $query['status'] ?? null,
                'model' => $query['model'] ?? null,
                'date_from' => $query['date_from'] ?? null,
                'date_to' => $query['date_to'] ?? null,
                'has_pending_action' => $query['has_pending_action'] ?? null,
                'conversation_id' => $query['conversation_id'] ?? null,
            ]);

            $this->logAdminAudit('admin_ai_conversations_viewed', $user, $request, [
                'data' => [
                    'limit' => $query['limit'] ?? 20,
                    'actor_id' => $query['actor_id'] ?? null,
                    'status' => $query['status'] ?? null,
                    'model' => $query['model'] ?? null,
                    'date_from' => $query['date_from'] ?? null,
                    'date_to' => $query['date_to'] ?? null,
                    'has_pending_action' => $query['has_pending_action'] ?? null,
                    'conversation_id' => $query['conversation_id'] ?? null,
                ],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => $items,
            ]);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI conversation list error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to fetch AI conversation history',
                'code' => 'AI_CONVERSATIONS_ERROR',
            ], 500);
        }
    }

    public function conversationDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            if ($this->agentService === null) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI assistant is not configured.',
                    'code' => 'AI_DISABLED',
                ], 503);
            }

            $conversationId = isset($args['conversation_id']) ? trim((string) $args['conversation_id']) : '';
            if ($conversationId === '') {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'conversation_id is required',
                    'code' => 'INVALID_CONVERSATION_ID',
                ], 422);
            }

            $detail = $this->agentService->getConversationDetail($conversationId);
            $this->logAdminAudit('admin_ai_conversation_viewed', $user, $request, [
                'conversation_id' => $conversationId,
                'data' => ['conversation_id' => $conversationId],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => $detail,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->json($response, [
                'success' => false,
                'error' => $exception->getMessage(),
                'code' => 'INVALID_CONVERSATION_ID',
            ], 422);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI conversation detail error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to fetch AI conversation detail',
                'code' => 'AI_CONVERSATION_DETAIL_ERROR',
            ], 500);
        }
    }

    public function generateAnnouncementDraft(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            if (!$this->announcementAiService->isEnabled()) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI assistant is not configured. Please set LLM_API_KEY on the server.',
                    'code' => 'AI_DISABLED',
                ], 503);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $action = isset($data['action']) ? strtolower(trim((string) $data['action'])) : AdminAnnouncementAiService::ACTION_GENERATE;
            if (!in_array($action, AdminAnnouncementAiService::SUPPORTED_ACTIONS, true)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Unsupported action. Use generate, rewrite, compress, or convert.',
                    'code' => 'INVALID_ACTION',
                ], 422);
            }

            $title = trim((string) ($data['title'] ?? ''));
            $content = trim((string) ($data['content'] ?? ''));
            $instruction = trim((string) ($data['instruction'] ?? ''));
            $priority = strtolower(trim((string) ($data['priority'] ?? 'normal')));
            $contentFormat = strtolower(trim((string) ($data['content_format'] ?? 'html')));

            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Unsupported priority. Use low, normal, high, or urgent.',
                    'code' => 'INVALID_PRIORITY',
                ], 422);
            }

            if (!in_array($contentFormat, ['text', 'html'], true)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Unsupported content_format. Use text or html.',
                    'code' => 'INVALID_CONTENT_FORMAT',
                ], 422);
            }

            if ($title === '' && $content === '' && $instruction === '') {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'At least one of title, content, or instruction is required.',
                    'code' => 'INVALID_INPUT',
                ], 422);
            }

            $source = null;
            if (isset($data['source']) && is_string($data['source'])) {
                $source = trim($data['source']);
            }
            if ($source === '') {
                $source = null;
            }

            $logContext = [
                'request_id' => $request->getAttribute('request_id'),
                'actor_type' => 'admin',
                'actor_id' => $user['id'] ?? null,
                'source' => $source ?? $request->getUri()->getPath(),
            ];

            $result = $this->announcementAiService->generateDraft([
                'action' => $action,
                'title' => $title,
                'content' => $content,
                'instruction' => $instruction,
                'priority' => $priority,
                'content_format' => $contentFormat,
            ], $logContext);

            if (!($result['success'] ?? false)) {
                $this->logAdminAudit('admin_ai_announcement_draft_invalid', $user, $request, [
                    'data' => ['action' => $action, 'content_format' => $contentFormat],
                ], 'failed');
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI returned an invalid announcement draft. Please retry.',
                    'code' => 'AI_INVALID_RESPONSE',
                ], 502);
            }

            $this->logAdminAudit('admin_ai_announcement_draft_generated', $user, $request, [
                'data' => [
                    'action' => $action,
                    'priority' => $priority,
                    'content_format' => $contentFormat,
                    'source' => $source ?? $request->getUri()->getPath(),
                ],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => $result['result'] ?? null,
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'timestamp' => gmdate(DATE_ATOM),
                ]),
            ]);
        } catch (AdminAnnouncementAiUnavailableException $runtimeException) {
            $this->logException($runtimeException, $request, 'AdminAI announcement draft unavailable');
            $this->logAdminAudit('admin_ai_announcement_draft_failed', $user ?? null, $request, [
                'data' => ['reason' => 'provider_unavailable'],
            ], 'failed');
            return $this->json($response, [
                'success' => false,
                'error' => 'AI provider is temporarily unavailable. Please try again later.',
                'code' => 'AI_UNAVAILABLE',
            ], 503);
        } catch (AdminAnnouncementAiException $runtimeException) {
            $this->logException($runtimeException, $request, 'AdminAI announcement draft runtime error');
            $this->logAdminAudit('admin_ai_announcement_draft_failed', $user ?? null, $request, [
                'data' => ['reason' => 'runtime_exception'],
            ], 'failed');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to generate announcement draft',
                'code' => 'AI_ANNOUNCEMENT_ERROR',
            ], 500);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI announcement draft unexpected error');
            $this->logAdminAudit('admin_ai_announcement_draft_failed', $user ?? null, $request, [
                'data' => ['reason' => 'unexpected_exception'],
            ], 'failed');
            return $this->json($response, [
                'success' => false,
                'error' => 'Unexpected server error',
                'code' => 'AI_ANNOUNCEMENT_SERVER_ERROR',
            ], 500);
        }
    }

    public function analyze(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            if (!$this->intentService->isEnabled()) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI assistant is not configured. Please set LLM_API_KEY on the server.',
                    'code' => 'AI_DISABLED',
                ], 503);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $query = isset($data['query']) ? trim((string)$data['query']) : '';
            if ($query === '') {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Field "query" is required',
                    'code' => 'INVALID_QUERY',
                ], 422);
            }

            $context = [];
            if (isset($data['context']) && is_array($data['context'])) {
                $context = $data['context'];
            }

            $source = null;
            if (isset($data['source']) && is_string($data['source'])) {
                $source = trim($data['source']);
            } elseif (isset($context['activeRoute']) && is_string($context['activeRoute'])) {
                $source = trim($context['activeRoute']);
            }
            if ($source === '') {
                $source = null;
            }

            $mode = isset($data['mode']) && is_string($data['mode'])
                ? strtolower($data['mode'])
                : 'suggest';
            if (!in_array($mode, ['suggest', 'analyze'], true)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Unsupported mode. Use "suggest" or "analyze".',
                    'code' => 'INVALID_MODE',
                ], 422);
            }

            $logContext = [
                'request_id' => $request->getAttribute('request_id'),
                'actor_type' => 'admin',
                'actor_id' => $user['id'] ?? null,
                'source' => $source ?? $request->getUri()->getPath(),
            ];
            $result = $this->intentService->analyzeIntent($query, $context, $logContext);

            $commandsFingerprint = $this->commandRepository->getFingerprint();

            $payload = [
                'success' => true,
                'intent' => $result['intent'] ?? null,
                'alternatives' => $result['alternatives'] ?? [],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'mode' => $mode,
                    'timestamp' => gmdate(DATE_ATOM),
                    'commandsFingerprint' => $commandsFingerprint,
                ]),
                'capabilities' => [
                    'fingerprint' => $commandsFingerprint,
                    'source' => $this->commandRepository->getActivePath(),
                    'lastModified' => $this->commandRepository->getLastModified(),
                ],
            ];

            $this->logAdminAudit('admin_ai_intent_analyzed', $user, $request, [
                'data' => [
                    'mode' => $mode,
                    'source' => $source ?? $request->getUri()->getPath(),
                    'intent_type' => $result['intent']['type'] ?? null,
                ],
            ]);

            return $this->json($response, $payload);
        } catch (\RuntimeException $runtimeException) {
            if ($runtimeException->getMessage() === 'LLM_UNAVAILABLE') {
                $this->logException($runtimeException, $request, 'AdminAI: LLM unavailable');
                $this->logAdminAudit('admin_ai_intent_failed', $user ?? null, $request, [
                    'data' => ['reason' => 'provider_unavailable'],
                ], 'failed');
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI provider is temporarily unavailable. Please try again later.',
                    'code' => 'AI_UNAVAILABLE',
                ], 503);
            }

            $this->logException($runtimeException, $request, 'AdminAI runtime error');
            $this->logAdminAudit('admin_ai_intent_failed', $user ?? null, $request, [
                'data' => ['reason' => 'runtime_exception'],
            ], 'failed');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to analyze the command',
                'code' => 'AI_ANALYZE_ERROR',
            ], 500);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI unexpected error');
            $this->logAdminAudit('admin_ai_intent_failed', $user ?? null, $request, [
                'data' => ['reason' => 'unexpected_exception'],
            ], 'failed');
            return $this->json($response, [
                'success' => false,
                'error' => 'Unexpected server error',
                'code' => 'AI_INTENT_SERVER_ERROR',
            ], 500);
        }
    }

    public function diagnostics(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, [
                    'success' => false,
                    'error' => 'Admin access required',
                ], 403);
            }

            $queryParams = $request->getQueryParams();
            $performCheck = false;
            $flag = $queryParams['check'] ?? $queryParams['connectivity'] ?? $queryParams['ping'] ?? null;
            if (is_string($flag)) {
                $performCheck = in_array(strtolower($flag), ['1', 'true', 'yes', 'on'], true);
            } elseif (is_bool($flag)) {
                $performCheck = $flag;
            }

            $diagnostics = $this->intentService->getDiagnostics($performCheck);
            $diagnostics['commands']['fingerprint'] = $this->commandRepository->getFingerprint();
            $diagnostics['commands']['source'] = $this->commandRepository->getActivePath();
            $diagnostics['commands']['lastModified'] = $this->commandRepository->getLastModified();

            $this->logAdminAudit('admin_ai_diagnostics_viewed', $user, $request, [
                'data' => ['perform_check' => $performCheck],
            ]);

            return $this->json($response, [
                'success' => true,
                'diagnostics' => $diagnostics,
            ]);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI diagnostics error');
            $this->logAdminAudit('admin_ai_diagnostics_failed', $user ?? null, $request, [
                'data' => ['reason' => 'unexpected_exception'],
            ], 'failed');

            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to gather AI diagnostics',
                'code' => 'AI_DIAGNOSTICS_ERROR',
            ], 500);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * @param mixed $targets
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWorkspaceNavigationTargets(mixed $targets): array
    {
        if (!is_array($targets)) {
            return [];
        }

        $items = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $route = isset($target['route']) && is_string($target['route']) ? trim($target['route']) : '';
            if ($route === '') {
                continue;
            }

            $items[] = [
                'id' => isset($target['id']) && is_string($target['id']) ? $target['id'] : $route,
                'label' => isset($target['label']) && is_string($target['label']) ? $target['label'] : $route,
                'description' => isset($target['description']) && is_string($target['description']) ? $target['description'] : null,
                'route' => $route,
            ];
        }

        return $items;
    }

    /**
     * @param mixed $actions
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWorkspaceQuickActions(mixed $actions): array
    {
        if (!is_array($actions)) {
            return [];
        }

        $items = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $route = isset($action['route']) && is_string($action['route']) ? trim($action['route']) : '';
            if ($route === '') {
                continue;
            }

            $query = isset($action['query']) && is_array($action['query']) ? $action['query'] : [];
            $items[] = [
                'id' => isset($action['id']) && is_string($action['id']) ? $action['id'] : $route,
                'label' => isset($action['label']) && is_string($action['label']) ? $action['label'] : $route,
                'description' => isset($action['description']) && is_string($action['description']) ? $action['description'] : null,
                'route_id' => isset($action['routeId']) && is_string($action['routeId']) ? $action['routeId'] : null,
                'route' => $route,
                'mode' => isset($action['mode']) && is_string($action['mode']) ? $action['mode'] : 'shortcut',
                'query' => $query,
            ];
        }

        return $items;
    }

    /**
     * @param mixed $actions
     * @return array<int,array<string,mixed>>
     */
    private function normalizeWorkspaceManagementActions(mixed $actions): array
    {
        if (!is_array($actions)) {
            return [];
        }

        $items = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $name = isset($action['name']) && is_string($action['name']) ? trim($action['name']) : '';
            if ($name === '') {
                continue;
            }

            $items[] = [
                'name' => $name,
                'label' => isset($action['label']) && is_string($action['label']) ? $action['label'] : $name,
                'description' => isset($action['description']) && is_string($action['description']) ? $action['description'] : null,
                'risk_level' => isset($action['risk_level']) && is_string($action['risk_level']) ? $action['risk_level'] : null,
                'requires_confirmation' => !empty($action['requires_confirmation']),
                'context_hints' => array_values(array_filter(
                    isset($action['contextHints']) && is_array($action['contextHints']) ? $action['contextHints'] : [],
                    static fn ($item): bool => is_string($item) && trim($item) !== ''
                )),
                'requirements' => $this->normalizeWorkspaceRequirements($action['requires'] ?? []),
            ];
        }

        return $items;
    }

    /**
     * @param mixed $requirements
     * @return array<int,string>
     */
    private function normalizeWorkspaceRequirements(mixed $requirements): array
    {
        if (!is_array($requirements)) {
            return [];
        }

        $labels = [];
        foreach ($requirements as $requirement) {
            if (is_string($requirement) && trim($requirement) !== '') {
                $labels[] = trim($requirement);
                continue;
            }

            if (!is_array($requirement)) {
                continue;
            }

            if (isset($requirement['label']) && is_string($requirement['label']) && trim($requirement['label']) !== '') {
                $labels[] = trim($requirement['label']);
                continue;
            }

            $anyOf = isset($requirement['anyOf']) && is_array($requirement['anyOf']) ? $requirement['anyOf'] : [];
            $alternatives = array_values(array_filter($anyOf, static fn ($item): bool => is_string($item) && trim($item) !== ''));
            if ($alternatives !== []) {
                $labels[] = implode(' / ', $alternatives);
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param mixed $managementActions
     * @return array<int,array<string,string>>
     */
    private function buildWorkspaceStarterPrompts(mixed $managementActions): array
    {
        $actionNames = [];
        if (is_array($managementActions)) {
            foreach ($managementActions as $action) {
                if (is_array($action) && isset($action['name']) && is_string($action['name']) && trim($action['name']) !== '') {
                    $actionNames[] = trim($action['name']);
                }
            }
        }

        $actionNames = array_values(array_unique($actionNames));
        $prompts = [];
        $catalog = [
            'generate_admin_report' => [
                'id' => 'daily-ops-brief',
                'label' => '生成运营简报',
                'prompt' => '帮我总结最近 7 天后台运营、待处理事项和 AI 使用情况，给我一个简洁的管理简报。',
            ],
            'get_pending_carbon_records' => [
                'id' => 'pending-carbon-review',
                'label' => '梳理待审碳记录',
                'prompt' => '帮我查看当前待审核的碳记录，并按优先级告诉我先处理哪些。',
            ],
            'search_users' => [
                'id' => 'user-investigation',
                'label' => '定位用户问题',
                'prompt' => '帮我搜索用户，并告诉我排查用户账号问题时最先应该看哪些信息。',
            ],
            'get_exchange_orders' => [
                'id' => 'pending-exchanges',
                'label' => '处理兑换订单',
                'prompt' => '帮我查看当前待处理的兑换订单，并总结每单需要的下一步动作。',
            ],
            'search_system_logs' => [
                'id' => 'trace-request',
                'label' => '追踪异常请求',
                'prompt' => '帮我搜索最近的系统日志，并告诉我定位一次后台异常最有效的检索方式。',
            ],
            'get_llm_usage_analytics' => [
                'id' => 'llm-usage',
                'label' => '检查 AI 用量',
                'prompt' => '帮我总结最近 30 天管理员 AI 的会话量、模型分布和异常信号。',
            ],
            'create_user' => [
                'id' => 'create-admin-account',
                'label' => '创建账号模板',
                'prompt' => '我要创建一个新后台账号。先告诉我需要准备哪些字段，再帮我生成可执行的操作草案。',
            ],
        ];

        foreach ($actionNames as $actionName) {
            if (isset($catalog[$actionName])) {
                $prompts[] = $catalog[$actionName];
            }
            if (count($prompts) >= 6) {
                break;
            }
        }

        if ($prompts === []) {
            $prompts[] = [
                'id' => 'generic-admin-ai',
                'label' => '开始一个治理会话',
                'prompt' => '帮我看看当前后台最值得优先处理的任务，并给我一个可执行的下一步建议。',
            ];
        }

        return $prompts;
    }

    private function logAdminAudit(string $action, ?array $user, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $adminId = isset($user['id']) && is_numeric((string)$user['id']) ? (int)$user['id'] : null;
            $this->auditLogService->logAdminOperation($action, $adminId, 'admin_ai', array_merge([
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => (string)$request->getUri()->getPath(),
                'status' => $status,
                'conversation_id' => $context['conversation_id'] ?? null,
                'request_data' => $context['data'] ?? null,
            ], $context));
        } catch (\Throwable $ignore) {
            // 审计日志失败不阻断主流程
        }
    }

    private function logException(\Throwable $exception, Request $request, string $context): void
    {
        if ($this->errorLogService) {
            try {
                $this->errorLogService->logException($exception, $request, ['context' => $context]);
                return;
            } catch (\Throwable $loggingError) {
                // fall back to logger below
                if ($this->logger) {
                    $this->logger->error('Failed to log admin AI exception via ErrorLogService', [
                        'error' => $loggingError->getMessage(),
                    ]);
                }
            }
        }

        if ($this->logger) {
            $this->logger->error($context . ': ' . $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        } else {
            error_log(sprintf('%s: %s', $context, $exception->getMessage()));
        }
    }
}

