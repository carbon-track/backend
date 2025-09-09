<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\SystemLogService;
use CarbonTrack\Services\AuthService;
use Monolog\Logger;

class RequestLoggingMiddleware implements MiddlewareInterface
{
    private SystemLogService $systemLogService;
    private AuthService $authService;
    private Logger $logger;

    private const EXCLUDE_PATHS = [
        '/',
        '/api/v1',
        '/api/v1/health',
    ];

    public function __construct(SystemLogService $systemLogService, AuthService $authService, Logger $logger)
    {
        $this->systemLogService = $systemLogService;
        $this->authService = $authService;
        $this->logger = $logger;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $start = microtime(true);
    $requestId = $request->getHeaderLine('X-Request-ID') ?: $this->generateRequestId();
    // 让后续服务/中间件能够获取 request_id：
    $request = $request->withAttribute('request_id', $requestId);
    // 将其写回 server params（兼容旧代码使用 $_SERVER['HTTP_X_REQUEST_ID']）
    $_SERVER['HTTP_X_REQUEST_ID'] = $requestId;

        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $skip = $this->shouldSkip($path);

        $userId = null;
        try {
            $user = $this->authService->getCurrentUser($request);
            if ($user) { $userId = $user['id'] ?? null; }
        } catch (\Throwable $e) {
            // ignore auth errors for logging middleware
        }

        $parsedBody = null;
        if (!$skip) {
            try { $parsedBody = $request->getParsedBody(); } catch (\Throwable $e) { $parsedBody = null; }
        }

        $response = $handler->handle($request);

        if (!$skip) {
            $duration = (microtime(true) - $start) * 1000.0;
            $respBody = null;
            try {
                // clone body stream contents cautiously (may be non-seekable)
                $stream = $response->getBody();
                if ($stream->isSeekable()) {
                    $pos = $stream->tell();
                    $stream->rewind();
                    $respBody = $stream->getContents();
                    $stream->seek($pos);
                }
            } catch (\Throwable $e) { $respBody = null; }

            $this->systemLogService->log([
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path,
                'status_code' => $response->getStatusCode(),
                'user_id' => $userId,
                'ip_address' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'duration_ms' => round($duration, 2),
                'request_body' => $parsedBody,
                'response_body' => $this->decodeIfJson($respBody)
            ]);
        }

    return $response->withHeader('X-Request-ID', $requestId);
    }

    private function shouldSkip(string $path): bool
    {
        foreach (self::EXCLUDE_PATHS as $skip) {
            if ($path === $skip) return true;
        }
        // skip system log endpoints themselves to prevent recursion once added
        if (strpos($path, '/api/v1/admin/system-logs') === 0) return true;
        return false;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function decodeIfJson(?string $body)
    {
        if ($body === null) return null;
        $trim = trim($body);
        if ($trim === '') return null;
        if (($trim[0] === '{' && substr($trim, -1) === '}') || ($trim[0] === '[' && substr($trim, -1) === ']')) {
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        return $trim;
    }
}
