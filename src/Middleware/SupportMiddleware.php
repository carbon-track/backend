<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class SupportMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $authService,
        private ?ErrorLogService $errorLogService = null
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            $user = $request->getAttribute('authenticated_user');
            if (!is_array($user)) {
                $user = $this->authService->getCurrentUser($request);
            }

            if (!$user) {
                return $this->jsonError(401, 'Authentication required', 'AUTH_REQUIRED');
            }

            if (!$this->authService->isSupportUser($user)) {
                return $this->jsonError(403, 'Support access required', 'SUPPORT_REQUIRED');
            }

            return $handler->handle($request->withAttribute('user', $user));
        } catch (\Throwable $e) {
            $this->logExceptionWithFallback($e, $request, 'SupportMiddleware error: ' . $e->getMessage());
            return $this->jsonError(500, 'Internal server error', 'INTERNAL_ERROR');
        }
    }

    private function jsonError(int $status, string $message, string $code): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message,
            'error' => $message,
            'code' => $code,
        ]));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function logExceptionWithFallback(\Throwable $exception, Request $request, string $contextMessage): void
    {
        if ($this->errorLogService) {
            try {
                $this->errorLogService->logException($exception, $request, ['context_message' => $contextMessage]);
                return;
            } catch (\Throwable $loggingError) {
                error_log('ErrorLogService logging failed: ' . $loggingError->getMessage());
            }
        }

        error_log($contextMessage);
    }
}
