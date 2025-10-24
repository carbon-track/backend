<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Services\AdminAiCommandRepository;
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
        private AdminAiCommandRepository $commandRepository,
        private ?ErrorLogService $errorLogService = null,
        private ?LoggerInterface $logger = null
    ) {
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

            $result = $this->intentService->analyzeIntent($query, $context);

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

            return $this->json($response, $payload);
        } catch (\RuntimeException $runtimeException) {
            if ($runtimeException->getMessage() === 'LLM_UNAVAILABLE') {
                $this->logException($runtimeException, $request, 'AdminAI: LLM unavailable');
                return $this->json($response, [
                    'success' => false,
                    'error' => 'AI provider is temporarily unavailable. Please try again later.',
                    'code' => 'AI_UNAVAILABLE',
                ], 503);
            }

            $this->logException($runtimeException, $request, 'AdminAI runtime error');
            return $this->json($response, [
                'success' => false,
                'error' => 'Failed to analyze the command',
                'code' => 'AI_ANALYZE_ERROR',
            ], 500);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI unexpected error');
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

            return $this->json($response, [
                'success' => true,
                'diagnostics' => $diagnostics,
            ]);
        } catch (\Throwable $throwable) {
            $this->logException($throwable, $request, 'AdminAI diagnostics error');

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

