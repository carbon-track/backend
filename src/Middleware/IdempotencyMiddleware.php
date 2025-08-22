<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Models\IdempotencyRecord;
use Slim\Psr7\Response;
use Monolog\Logger;

class IdempotencyMiddleware implements MiddlewareInterface
{
    private DatabaseService $db;
    private Logger $logger;
    private array $idempotentMethods = ['POST', 'PUT', 'PATCH'];
    private array $sensitiveRoutes = [
        '/api/v1/auth/register',
        '/api/v1/carbon-track/record',
        '/api/v1/exchange',
        '/api/v1/messages'
    ];

    public function __construct(DatabaseService $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();
        
        // Only apply idempotency to specific methods and routes
        if (!in_array($method, $this->idempotentMethods) || !$this->isSensitiveRoute($uri)) {
            return $handler->handle($request);
        }
        
        $idempotencyKey = $request->getHeaderLine('X-Request-ID');
        
        if (empty($idempotencyKey)) {
            return $this->badRequestResponse('X-Request-ID header is required for this operation');
        }
        
        // Validate UUID format
        if (!$this->isValidUuid($idempotencyKey)) {
            return $this->badRequestResponse('X-Request-ID must be a valid UUID');
        }
        
    try {
            // Check if this request has been processed before
            $existingRecord = IdempotencyRecord::where('idempotency_key', $idempotencyKey)
                ->where('created_at', '>', date('Y-m-d H:i:s', strtotime('-24 hours'))) // Only check last 24 hours
                ->first();
            
            if ($existingRecord) {
                $this->logger->info('Idempotent request detected', [
                    'idempotency_key' => $idempotencyKey,
                    'original_status' => $existingRecord->response_status,
                    'uri' => $uri
                ]);
                
                // Return the cached response
                $response = new Response();
                $response->getBody()->write($existingRecord->response_body);
                return $response
                    ->withStatus($existingRecord->response_status)
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Idempotent-Replay', 'true');
            }
            
            // Process the request
            $response = $handler->handle($request);
            
            // Store the response for future idempotency checks
            $this->storeIdempotencyRecord($idempotencyKey, $request, $response);
            
            return $response;
            
    } catch (\Throwable $e) {
            $this->logger->error('Idempotency middleware error', [
                'error' => $e->getMessage(),
                'idempotency_key' => $idempotencyKey,
                'uri' => $uri
            ]);
            
            // Continue with normal processing if idempotency check fails
            return $handler->handle($request);
        }
    }

    private function isSensitiveRoute(string $uri): bool
    {
        foreach ($this->sensitiveRoutes as $route) {
            if (str_starts_with($uri, $route)) {
                return true;
            }
        }
        return false;
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    private function storeIdempotencyRecord(string $idempotencyKey, ServerRequestInterface $request, ResponseInterface $response): void
    {
    try {
            $userId = $request->getAttribute('user_id');
            $responseBody = (string) $response->getBody();
            
            // Reset body stream position for subsequent reads
            $response->getBody()->rewind();
            
            IdempotencyRecord::create([
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'request_method' => $request->getMethod(),
                'request_uri' => $request->getUri()->getPath(),
                'request_body' => json_encode($request->getParsedBody()),
                'response_status' => $response->getStatusCode(),
                'response_body' => $responseBody,
                'ip_address' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent')
            ]);
            
    } catch (\Throwable $e) {
            $this->logger->error('Failed to store idempotency record', [
                'error' => $e->getMessage(),
                'idempotency_key' => $idempotencyKey
            ]);
        }
    }

    private function badRequestResponse(string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'BAD_REQUEST'
        ]));
        
        return $response
            ->withStatus(400)
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

