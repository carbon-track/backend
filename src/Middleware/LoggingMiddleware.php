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
        
        // Log request with error handling
        try {
            $this->logger->info('Request received', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'ip' => $this->getClientIp($request),
                'user_agent' => $request->getHeaderLine('User-Agent')
            ]);
        } catch (\Exception $e) {
            // 如果日志记录失败，不要中断请求处理
            error_log('Logging failed: ' . $e->getMessage());
        }

        try {
            $response = $handler->handle($request);
            
            $duration = microtime(true) - $start;
            
            // Log response with error handling
            try {
                $this->logger->info('Request completed', [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'status' => $response->getStatusCode(),
                    'duration' => round($duration * 1000, 2) . 'ms'
                ]);
            } catch (\Exception $e) {
                // 如果日志记录失败，不要中断响应
                error_log('Logging failed: ' . $e->getMessage());
            }
            
            return $response;
            
        } catch (\Exception $e) {
            $duration = microtime(true) - $start;
            
            // Log error with error handling
            try {
                $this->logger->error('Request failed', [
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'error' => $e->getMessage(),
                    'duration' => round($duration * 1000, 2) . 'ms'
                ]);
            } catch (\Exception $logError) {
                // 如果日志记录失败，至少记录到error_log
                error_log('Request failed and logging failed: ' . $e->getMessage() . ' | Log error: ' . $logError->getMessage());
            }
            
            throw $e;
        }
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        if (!empty($serverParams['HTTP_CF_CONNECTING_IP'])) {
            return $serverParams['HTTP_CF_CONNECTING_IP'];
        }

        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}

