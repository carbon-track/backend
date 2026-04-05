<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use CarbonTrack\Middleware\SupportMiddleware;
use CarbonTrack\Services\AuthService;
use PHPUnit\Framework\TestCase;

class SupportMiddlewareTest extends TestCase
{
    public function testRejectsWhenUnauthenticated(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);

        $middleware = new SupportMiddleware($auth);
        $response = $middleware->process(
            makeRequest('GET', '/api/v1/support/tickets'),
            $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class)
        );

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Authentication required', $payload['message']);
        $this->assertSame('Authentication required', $payload['error']);
        $this->assertSame('AUTH_REQUIRED', $payload['code']);
    }

    public function testRejectsRegularUsers(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 8, 'role' => 'user', 'is_admin' => false]);
        $auth->method('isSupportUser')->willReturn(false);

        $middleware = new SupportMiddleware($auth);
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

        $middleware = new SupportMiddleware($auth);
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
}
