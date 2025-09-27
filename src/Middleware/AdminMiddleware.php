<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;

class AdminMiddleware implements MiddlewareInterface
{
    private AuthService $authService;
    private ?ErrorLogService $errorLogService;

    public function __construct(AuthService $authService, ?ErrorLogService $errorLogService = null)
    {
        $this->authService = $authService;
        $this->errorLogService = $errorLogService;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        try {
            // 获取当前用户
            $user = $this->authService->getCurrentUser($request);
            
            if (!$user) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Authentication required',
                    'code' => 'AUTH_REQUIRED'
                ]));
                return $response
                    ->withStatus(401)
                    ->withHeader('Content-Type', 'application/json');
            }

            // 检查是否为管理员
            if (!$this->authService->isAdminUser($user)) {
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ]));
                return $response
                    ->withStatus(403)
                    ->withHeader('Content-Type', 'application/json');
            }

            // 将用户信息添加到请求属性中
            $request = $request->withAttribute('user', $user);
            
            return $handler->handle($request);
            
        } catch (\Exception $e) {
            $this->logExceptionWithFallback($e, $request, 'AdminMiddleware error: ' . $e->getMessage());
            
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error',
                'code' => 'INTERNAL_ERROR'
            ]));
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }


    private function logExceptionWithFallback(\Throwable $exception, Request $request, string $contextMessage): void
    {
        if ($this->errorLogService) {
            try {
                $this->errorLogService->logException($exception, $request, ['context_message' => $contextMessage]);
                return;
            } catch (\Throwable $loggingError) {
                error_log('ErrorLogService logging failed: ' . $loggingError->getMessage());
            }
        }
        error_log($contextMessage);
    }

}
