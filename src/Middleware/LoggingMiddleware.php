<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Log\LoggerInterface;

class LoggingMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $start = microtime(true);
        
        // Log request
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'ip' => $this->getClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent')
        ]);

        try {
            $response = $handler->handle($request);
            
            $duration = microtime(true) - $start;
            
            // Log response
            $this->logger->info('Request completed', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'status' => $response->getStatusCode(),
                'duration' => round($duration * 1000, 2) . 'ms'
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $start;
            
            // Log error
            $this->logger->error('Request failed', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'error' => $e->getMessage(),
                'duration' => round($duration * 1000, 2) . 'ms'
            ]);
            
            throw $e;
        }
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}

