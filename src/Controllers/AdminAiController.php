<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AdminAiIntentService;
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
        private ?LoggerInterface $logger = null
    ) {
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

    private function logAdminAudit(string $action, ?array $user, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $adminId = isset($user['id']) && is_numeric((string)$user['id']) ? (int)$user['id'] : null;
            $this->auditLogService->logAdminOperation($action, $adminId, 'admin_ai', array_merge([
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => (string)$request->getUri()->getPath(),
                'status' => $status,
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

