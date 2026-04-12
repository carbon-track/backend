<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class SupportMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $authService,
        private LoggerInterface $logger,
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
                $this->logWithFallback('error', 'ErrorLogService logging failed for support middleware', [
                    'context_message' => $contextMessage,
                    'request_id' => $this->resolveRequestId($request),
                    'path' => (string) $request->getUri()->getPath(),
                    'method' => $request->getMethod(),
                    'exception_type' => get_class($exception),
                    'logging_exception_type' => get_class($loggingError),
                    'logging_error_message' => $loggingError->getMessage(),
                ]);
            }
        }

        $this->logWithFallback('warning', $contextMessage, [
            'request_id' => $this->resolveRequestId($request),
            'path' => (string) $request->getUri()->getPath(),
            'method' => $request->getMethod(),
            'exception_type' => get_class($exception),
            'exception_message' => $exception->getMessage(),
        ]);
    }

    private function resolveRequestId(Request $request): ?string
    {
        $attribute = $request->getAttribute('request_id');
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $header = trim($request->getHeaderLine('X-Request-ID'));
        if ($header !== '') {
            return $header;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logWithFallback(string $level, string $message, array $context): void
    {
        try {
            if ($level === 'error') {
                $this->logger->error($message, $context);
                return;
            }

            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // Swallow logger failures to preserve the original 500 response path.
        }
    }
}
