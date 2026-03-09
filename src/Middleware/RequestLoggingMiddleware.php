<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\SystemLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Support\Uuid;
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
        $requestId = $this->resolveRequestId($request->getHeaderLine('X-Request-ID'));
        $request = $request
            ->withHeader('X-Request-ID', $requestId)
            ->withAttribute('request_id', $requestId);
        // Allow legacy listeners that rely on $_SERVER to access the request id
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
            $serverParams = $this->snapshotServerParams($request);
            $ipAddress = $this->resolveClientIp($serverParams);

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
                'ip_address' => $ipAddress,
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'duration_ms' => round($duration, 2),
                'request_body' => $parsedBody,
                'response_body' => $this->decodeIfJson($respBody),
                'server_params' => $serverParams,
            ]);
        }

        return $response->withHeader('X-Request-ID', $requestId);
    }

    private function resolveRequestId(?string $incoming): string
    {
        $incoming = trim((string) $incoming);

        if ($incoming !== '' && Uuid::isValid($incoming)) {
            return strtolower($incoming);
        }

        return Uuid::generateV4();
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

    /**
     * Merge PSR-7 server params with the global $_SERVER snapshot for richer metadata.
     */
    private function snapshotServerParams(Request $request): array
    {
        $psrServer = $request->getServerParams();
        if (!is_array($psrServer)) {
            $psrServer = [];
        }
        $globals = $_SERVER ?? [];
        return array_replace($globals, $psrServer);
    }

    /**
     * Resolve client IP preferring Cloudflare's connecting IP headers when present.
     */
    private function resolveClientIp(array $serverParams): ?string
    {
        $candidates = [
            $serverParams['HTTP_CF_CONNNECTING_IP'] ?? null, // handle potential typo key
            $serverParams['HTTP_CF_CONNECTING_IP'] ?? null,
            $serverParams['CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_CF_CONNNECTING_IP'] ?? null,
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['CF_CONNECTING_IP'] ?? null,
            $serverParams['REMOTE_ADDR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ($value === '') {
                continue;
            }
            $first = trim(explode(',', $value)[0]);
            if ($first === '') {
                continue;
            }
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return null;
    }
}
