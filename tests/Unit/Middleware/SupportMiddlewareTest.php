<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use CarbonTrack\Middleware\SupportMiddleware;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportMiddlewareTest extends TestCase
{
    public function testRejectsWhenUnauthenticated(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);

        $middleware = new SupportMiddleware($auth, $this->createMock(LoggerInterface::class));
        $response = $middleware->process(
            makeRequest('GET', '/api/v1/support/tickets')->withAttribute('request_id', 'req-support-auth'),
            $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class)
        );

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Authentication required', $payload['message']);
        $this->assertSame('Authentication required', $payload['error']);
        $this->assertSame('AUTH_REQUIRED', $payload['code']);
        $this->assertSame('req-support-auth', $payload['request_id']);
    }

    public function testRejectsRegularUsers(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 8, 'role' => 'user', 'is_admin' => false]);
        $auth->method('isSupportUser')->willReturn(false);

        $middleware = new SupportMiddleware($auth, $this->createMock(LoggerInterface::class));
        $response = $middleware->process(
            makeRequest('GET', '/api/v1/support/tickets'),
            $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class)
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Support access required', $payload['message']);
        $this->assertSame('Support access required', $payload['error']);
        $this->assertSame('SUPPORT_REQUIRED', $payload['code']);
    }

    public function testAllowsSupportUsers(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 3, 'role' => 'support', 'is_admin' => false, 'is_support' => true]);
        $auth->method('isSupportUser')->willReturn(true);

        $middleware = new SupportMiddleware($auth, $this->createMock(LoggerInterface::class));
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                TestCase::assertSame(3, $request->getAttribute('user')['id'] ?? null);
                $response = new \Slim\Psr7\Response();
                $response->getBody()->write('ok');
                return $response;
            }
        };

        $response = $middleware->process(makeRequest('GET', '/api/v1/support/tickets'), $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLogsStructuredFallbackWhenErrorLogServiceFails(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willThrowException(new \RuntimeException('auth exploded'));

        $errorLogService = $this->createMock(ErrorLogService::class);
        $errorLogService->expects($this->once())
            ->method('logException')
            ->willThrowException(new \RuntimeException('logger exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'ErrorLogService logging failed for support middleware',
                $this->callback(static function (array $context): bool {
                    return ($context['request_id'] ?? null) === 'req-support-1'
                        && ($context['path'] ?? null) === '/api/v1/support/tickets'
                        && ($context['method'] ?? null) === 'GET'
                        && ($context['exception_type'] ?? null) === \RuntimeException::class
                        && ($context['logging_exception_type'] ?? null) === \RuntimeException::class
                        && ($context['logging_error_message'] ?? null) === 'logger exploded';
                })
            );
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'SupportMiddleware error: auth exploded',
                $this->callback(static function (array $context): bool {
                    return ($context['request_id'] ?? null) === 'req-support-1'
                        && ($context['path'] ?? null) === '/api/v1/support/tickets'
                        && ($context['method'] ?? null) === 'GET'
                        && ($context['exception_type'] ?? null) === \RuntimeException::class
                        && ($context['exception_message'] ?? null) === 'auth exploded';
                })
            );

        $middleware = new SupportMiddleware($auth, $logger, $errorLogService);
        $response = $middleware->process(
            makeRequest('GET', '/api/v1/support/tickets')->withAttribute('request_id', 'req-support-1'),
            $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class)
        );

        $this->assertSame(500, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('INTERNAL_ERROR', $payload['code']);
    }
}
