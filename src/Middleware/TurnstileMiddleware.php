<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\AuditLogService;
use Slim\Psr7\Response;

class TurnstileMiddleware implements MiddlewareInterface
{
    private TurnstileService $turnstileService;
    private AuditLogService $auditLogService;
    private array $protectedRoutes = [
        '/api/v1/auth/register',
        '/api/v1/auth/login',
        '/api/v1/carbon-track/record',
        '/api/v1/exchange'
    ];

    public function __construct(TurnstileService $turnstileService, AuditLogService $auditLogService)
    {
        $this->turnstileService = $turnstileService;
        $this->auditLogService = $auditLogService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // Only apply Turnstile verification to protected routes and POST/PUT methods
        if (!$this->isProtectedRoute($uri) || !in_array($method, ['POST', 'PUT'])) {
            return $handler->handle($request);
        }

        // Skip verification in development/testing environment
        if (($_ENV['APP_ENV'] ?? 'production') === 'testing') {
            return $handler->handle($request);
        }

        $parsedBody = $request->getParsedBody();
        $turnstileToken = null;

        // Extract Turnstile token from request body or headers
        if (is_array($parsedBody) && isset($parsedBody['cf_turnstile_response'])) {
            $turnstileToken = $parsedBody['cf_turnstile_response'];
        } elseif ($request->hasHeader('X-Turnstile-Token')) {
            $turnstileToken = $request->getHeaderLine('X-Turnstile-Token');
        }

        if (empty($turnstileToken)) {
            $this->logTurnstileFailure($request, 'missing-token', 'Turnstile token is missing');
            return $this->forbiddenResponse('Turnstile verification is required for this operation');
        }

        // Verify Turnstile token
        $clientIp = $this->getClientIp($request);
        $verificationResult = $this->turnstileService->verify($turnstileToken, $clientIp);

        if (!$verificationResult['success']) {
            $this->logTurnstileFailure($request, $verificationResult['error'], $verificationResult['message']);
            return $this->forbiddenResponse('Turnstile verification failed: ' . $verificationResult['message']);
        }

        // Log successful verification
        $this->auditLogService->log([
            'user_id' => $request->getAttribute('user_id'),
            'action' => 'turnstile_verification_success',
            'entity_type' => 'security',
            'ip_address' => $clientIp,
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'notes' => 'Turnstile verification successful for ' . $uri,
            'new_value' => json_encode([
                'hostname' => $verificationResult['hostname'] ?? null,
                'action' => $verificationResult['action'] ?? null,
                'challenge_ts' => $verificationResult['challenge_ts'] ?? null
            ])
        ]);

        // Add verification result to request attributes for potential use in controllers
        $request = $request->withAttribute('turnstile_verified', true)
                          ->withAttribute('turnstile_result', $verificationResult);

        return $handler->handle($request);
    }

    private function isProtectedRoute(string $uri): bool
    {
        foreach ($this->protectedRoutes as $route) {
            if (str_starts_with($uri, $route)) {
                return true;
            }
        }
        return false;
    }

    private function logTurnstileFailure(ServerRequestInterface $request, string $error, string $message): void
    {
        $this->auditLogService->log([
            'user_id' => $request->getAttribute('user_id'),
            'action' => 'turnstile_verification_failure',
            'entity_type' => 'security',
            'ip_address' => $this->getClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'notes' => 'Turnstile verification failed for ' . $request->getUri()->getPath(),
            'new_value' => json_encode([
                'error' => $error,
                'message' => $message
            ])
        ]);
    }

    private function forbiddenResponse(string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'TURNSTILE_VERIFICATION_FAILED'
        ]));
        
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json');
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($serverParams[$header])) {
                $ip = $serverParams[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}

