<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Support\ClientIpResolver;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    private AuthService $authService;
    private AuditLogService $auditLogService;

    public function __construct(AuthService $authService, AuditLogService $auditLogService)
    {
        $this->authService = $authService;
        $this->auditLogService = $auditLogService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Test fallback now requires BOTH APP_ENV=testing AND an explicit
        // ALLOW_TEST_AUTH_FALLBACK opt-in. Production ignores both, so a misconfigured
        // env that accidentally sets APP_ENV=testing no longer hands out admin access.
        $isTesting = strtolower((string)($_ENV['APP_ENV'] ?? '')) === 'testing'
            && filter_var($_ENV['ALLOW_TEST_AUTH_FALLBACK'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse('Missing or invalid authorization header');
        }
        
        $token = substr($authHeader, 7); // Remove 'Bearer ' prefix
        
        try {
            $payload = $request->getAttribute('token_payload');
            if (
                $request->getAttribute('idempotency_validated_token') !== true
                || !is_array($payload)
            ) {
                $payload = $this->authService->validateToken($token);
            }
            
            // Add user info to request attributes
            $request = $request
                ->withAttribute('user_id', $payload['user_id'])
                ->withAttribute('user_uuid', $payload['uuid'] ?? null)
                ->withAttribute('user_email', $payload['email'])
                ->withAttribute('user_role', $payload['role'] ?? 'user')
                ->withAttribute('authenticated_user', $payload['user'] ?? null)
                ->withAttribute('token_payload', $payload);
            
            // Log authentication success
            $this->auditLogService->log([
                'user_id' => $payload['user_id'],
                'user_uuid' => $payload['uuid'] ?? null,
                'action' => 'auth_success',
                'operation_category' => 'authentication',
                'actor_type' => in_array(($payload['role'] ?? 'user'), ['admin', 'support'], true) ? ($payload['role'] ?? 'user') : 'user',
                'status' => 'success',
                'ip_address' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'data' => [
                    'message' => 'Token authentication successful',
                ],
            ]);
            
            return $handler->handle($request);
            
        } catch (\Exception $e) {
            $isVersionMismatch = $e->getMessage() === 'Token version mismatch';
            $this->auditLogService->log([
                'action' => $isVersionMismatch ? 'auth_token_version_mismatch' : 'auth_failure',
                'operation_category' => 'authentication',
                'actor_type' => 'system',
                'status' => 'failed',
                'ip_address' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'data' => [
                    'message' => 'Token authentication failed: ' . $e->getMessage(),
                ],
            ]);

            if ($isVersionMismatch) {
                return $this->unauthorizedResponse('Token has been revoked. Please sign in again.', 'TOKEN_VERSION_MISMATCH');
            }

            if ($isTesting) {
                $fallback = [
                    'user_id' => null,
                    'uuid' => null,
                    'email' => null,
                    'role' => 'admin',
                    'user' => [
                    'id' => null,
                    'uuid' => null,
                    'role' => 'admin',
                    'is_admin' => true,
                    'is_support' => true,
                    'username' => 'test-admin',
                    'email' => null,
                ],
            ];
                $request = $request
                    ->withAttribute('user_id', $fallback['user_id'])
                    ->withAttribute('user_uuid', $fallback['uuid'])
                    ->withAttribute('user_email', $fallback['email'])
                    ->withAttribute('user_role', $fallback['role'])
                    ->withAttribute('authenticated_user', $fallback['user'])
                    ->withAttribute('token_payload', $fallback);
                return $handler->handle($request);
            }

            return $this->unauthorizedResponse('Invalid or expired token');
        }
    }

    private function unauthorizedResponse(string $message, string $code = 'UNAUTHORIZED'): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ]));

        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        return ClientIpResolver::fromRequest($request, '0.0.0.0');
    }
}
