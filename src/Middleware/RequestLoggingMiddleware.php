<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\SystemLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Support\ClientIpResolver;
use CarbonTrack\Support\Uuid;
use Monolog\Logger;

class RequestLoggingMiddleware implements MiddlewareInterface
{
    private SystemLogService $systemLogService;
    private AuthService $authService;
    private Logger $logger;
    private bool $cronEndpointSystemLogsEnabled;

    private const EXCLUDE_PATHS = [
        '/',
        '/api/v1',
        '/api/v1/health',
    ];

    /**
     * Routes whose bodies (request + response) must never reach `system_logs`,
     * even after recursive sanitization, because they handle raw credentials,
     * verification codes, password resets, or freshly minted JWTs. We still keep
     * status / duration / metadata for observability, but drop the payload itself.
     *
     * @var string[]
     */
    private const SENSITIVE_BODY_PATH_PATTERNS = [
        '#^/api(?:/v1)?/auth/login(/.*)?$#',
        '#^/api(?:/v1)?/auth/register(/.*)?$#',
        '#^/api(?:/v1)?/auth/refresh(/.*)?$#',
        '#^/api(?:/v1)?/auth/change-password(/.*)?$#',
        '#^/api(?:/v1)?/auth/reset-password(/.*)?$#',
        '#^/api(?:/v1)?/auth/verify-email(/.*)?$#',
        '#^/api(?:/v1)?/auth/send-verification-code(/.*)?$#',
        '#^/api(?:/v1)?/auth/forgot-password(/.*)?$#',
        '#^/api/v1/auth/passkey/login/verify(/.*)?$#',
    ];

    public function __construct(
        SystemLogService $systemLogService,
        AuthService $authService,
        Logger $logger,
        bool $cronEndpointSystemLogsEnabled = true
    )
    {
        $this->systemLogService = $systemLogService;
        $this->authService = $authService;
        $this->logger = $logger;
        $this->cronEndpointSystemLogsEnabled = $cronEndpointSystemLogsEnabled;
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
        $userUuid = null;
        try {
            $user = $this->authService->getCurrentUser($request);
            if ($user) {
                $userId = $user['id'] ?? null;
                $userUuid = $user['uuid'] ?? null;
            }
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

            // For credential / verification routes we deliberately drop the bodies
            // to keep system_logs unable to leak passwords, codes, reset / verification
            // tokens, or freshly minted JWTs even if downstream sanitization regresses.
            $sensitive = $this->isSensitiveBodyPath($path);
            $loggedRequestBody = $sensitive ? '[REDACTED]' : $parsedBody;
            $loggedResponseBody = $sensitive ? '[REDACTED]' : $this->decodeIfJson($respBody);

            $this->systemLogService->log([
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path,
                'status_code' => $response->getStatusCode(),
                'user_id' => $userId,
                'user_uuid' => $userUuid,
                'ip_address' => $ipAddress,
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'duration_ms' => round($duration, 2),
                'request_body' => $loggedRequestBody,
                'response_body' => $loggedResponseBody,
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
        if (!$this->cronEndpointSystemLogsEnabled && $path === '/api/v1/cron/run') return true;
        // skip system log endpoints themselves to prevent recursion once added
        if (strpos($path, '/api/v1/admin/system-logs') === 0) return true;
        return false;
    }

    private function isSensitiveBodyPath(string $path): bool
    {
        foreach (self::SENSITIVE_BODY_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
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
        return ClientIpResolver::fromServerParams($serverParams);
    }
}
