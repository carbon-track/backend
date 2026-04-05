<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportTicketService;
use CarbonTrack\Services\TurnstileService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class SupportTicketController
{
    public function __construct(
        private SupportTicketService $supportTicketService,
        private AuthService $authService,
        private TurnstileService $turnstileService,
        private LoggerInterface $logger,
        private ErrorLogService $errorLogService
    ) {
    }

    public function createTicket(Request $request, Response $response): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        $payload = $this->body($request);
        if (!$this->turnstilePassed($payload['cf_turnstile_response'] ?? null, $request)) {
            return $this->json($response, ['success' => false, 'message' => 'Turnstile verification failed', 'code' => 'TURNSTILE_FAILED'], 403);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->createTicket($actor, $payload)], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to create support ticket');
        }
    }

    public function listMyTickets(Request $request, Response $response): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->listUserTickets((int) $actor['id'], $request->getQueryParams())]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to list support tickets');
        }
    }

    public function getMyTicket(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->getTicketDetailForUser((int) $actor['id'], $this->ticketId($args))]);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => 'Ticket not found', 'code' => 'TICKET_NOT_FOUND'], 404);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support ticket');
        }
    }

    public function addMyTicketMessage(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        $payload = $this->body($request);
        if (!$this->turnstilePassed($payload['cf_turnstile_response'] ?? null, $request)) {
            return $this->json($response, ['success' => false, 'message' => 'Turnstile verification failed', 'code' => 'TURNSTILE_FAILED'], 403);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->addUserMessage($actor, $this->ticketId($args), $payload)], 201);
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'Ticket not found' ? 404 : 422;
            $code = $status === 404 ? 'TICKET_NOT_FOUND' : 'INVALID_TICKET_STATE';
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => $code], $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to add support reply');
        }
    }

    public function listSupportTickets(Request $request, Response $response): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->listSupportTickets($actor, $request->getQueryParams())]);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to list support queue');
        }
    }

    public function listSupportAssignees(Request $request, Response $response): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->listSupportAssignees($actor)]);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support assignees');
        }
    }

    public function getSupportTicket(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->getTicketDetailForSupport($actor, $this->ticketId($args))]);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => 'Ticket not found', 'code' => 'TICKET_NOT_FOUND'], 404);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to load support queue ticket');
        }
    }

    public function addSupportTicketMessage(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->addSupportMessage($actor, $this->ticketId($args), $this->body($request))], 201);
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'Ticket not found' ? 404 : 422;
            $code = $status === 404 ? 'TICKET_NOT_FOUND' : 'INVALID_TICKET_STATE';
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => $code], $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to add support staff reply');
        }
    }

    public function updateSupportTicket(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->updateTicketFromSupport($actor, $this->ticketId($args), $this->body($request))]);
        } catch (\DomainException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'FORBIDDEN'], 403);
        } catch (\RuntimeException $e) {
            $status = $e->getMessage() === 'Ticket not found' ? 404 : 422;
            $code = $status === 404 ? 'TICKET_NOT_FOUND' : 'INVALID_TICKET_STATE';
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => $code], $status);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to update support ticket');
        }
    }

    public function createTransferRequest(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->createTransferRequest($actor, $this->ticketId($args), $this->body($request))], 201);
        } catch (\DomainException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'FORBIDDEN'], 403);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'TICKET_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to create support transfer request');
        }
    }

    public function reviewTransferRequest(Request $request, Response $response, array $args): Response
    {
        $actor = $this->currentUser($request);
        if ($actor === null) {
            return $this->json($response, ['success' => false, 'message' => 'Unauthorized', 'code' => 'UNAUTHORIZED'], 401);
        }

        $requestId = isset($args['requestId']) ? (int) $args['requestId'] : 0;
        if ($requestId <= 0) {
            return $this->json($response, ['success' => false, 'message' => 'Invalid request id', 'code' => 'VALIDATION_ERROR'], 422);
        }

        try {
            return $this->json($response, ['success' => true, 'data' => $this->supportTicketService->reviewTransferRequest($actor, $requestId, $this->body($request))]);
        } catch (\DomainException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'FORBIDDEN'], 403);
        } catch (\RuntimeException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'TRANSFER_REQUEST_NOT_FOUND'], 404);
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage(), 'code' => 'VALIDATION_ERROR'], 422);
        } catch (\Throwable $e) {
            return $this->error($request, $response, $e, 'Failed to review support transfer request');
        }
    }

    private function currentUser(Request $request): ?array
    {
        $user = $this->authService->getCurrentUser($request);
        return is_array($user) ? $user : null;
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function ticketId(array $args): int
    {
        $ticketId = isset($args['ticketId']) ? (int) $args['ticketId'] : 0;
        if ($ticketId <= 0) {
            throw new \InvalidArgumentException('Invalid ticket id');
        }
        return $ticketId;
    }

    private function turnstilePassed(mixed $token, Request $request): bool
    {
        $result = $this->turnstileService->verify(is_string($token) ? trim($token) : '', $this->clientIp($request));
        return (bool) ($result['success'] ?? false);
    }

    private function clientIp(Request $request): ?string
    {
        $serverParams = $request->getServerParams();
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $value = $serverParams[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            return str_contains($value, ',') ? trim(explode(',', $value)[0]) : trim($value);
        }
        return null;
    }

    private function error(Request $request, Response $response, \Throwable $e, string $message): Response
    {
        $this->logger->error($message, ['error' => $e->getMessage()]);
        try {
            $this->errorLogService->logException($e, $request, ['context_message' => $message]);
        } catch (\Throwable $loggingError) {
            $this->logger->error('Support ticket error logging failed', ['error' => $loggingError->getMessage()]);
        }
        return $this->json($response, ['success' => false, 'message' => $message, 'code' => 'INTERNAL_ERROR'], 500);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
