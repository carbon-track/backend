<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
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
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorizedResponse('Missing or invalid authorization header');
        }
        
        $token = substr($authHeader, 7); // Remove 'Bearer ' prefix
        
        try {
            $payload = $this->authService->validateToken($token);
            
            // Add user info to request attributes
            $request = $request
                ->withAttribute('user_id', $payload['user_id'])
                ->withAttribute('user_email', $payload['email'])
                ->withAttribute('user_role', $payload['role'] ?? 'user')
                ->withAttribute('token_payload', $payload);
            
            // Log authentication success
            $this->auditLogService->log([
                'user_id' => $payload['user_id'],
                'action' => 'auth_success',
                'entity_type' => 'auth',
                'ip_address' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Token authentication successful'
            ]);
            
            return $handler->handle($request);
            
        } catch (\Exception $e) {
            // Log authentication failure
            $this->auditLogService->log([
                'action' => 'auth_failure',
                'entity_type' => 'auth',
                'ip_address' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Token authentication failed: ' . $e->getMessage()
            ]);
            
            return $this->unauthorizedResponse('Invalid or expired token');
        }
    }

    private function unauthorizedResponse(string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'UNAUTHORIZED'
        ]));
        
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Check for IP from various headers (for load balancers, proxies)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancers
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];
        
        foreach ($headers as $header) {
            if (!empty($serverParams[$header])) {
                $ip = $serverParams[$header];
                // Handle comma-separated IPs (X-Forwarded-For)
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

