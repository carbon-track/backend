<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportAutomationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminSupportController
{
    public function __construct(
        private SupportAutomationService $supportAutomationService,
        private AuthService $authService,
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
        return $this->saveTag($request, $response, $this->numericId($args, 'id'), 200);
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
        return $this->saveRule($request, $response, $this->numericId($args, 'id'), 200);
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

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
