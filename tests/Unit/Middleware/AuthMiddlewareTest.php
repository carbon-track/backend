<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\AuthMiddleware;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;

class AuthMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(AuthMiddleware::class));
    }

    public function testProcessWithValidToken(): void
    {
        $auth = $this->createMock(AuthService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth->method('validateToken')->willReturn([
            'user_id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'email' => 'a@b.com',
            'role' => 'user',
            'user' => [
                'id' => 1,
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'email' => 'a@b.com',
                'is_admin' => false,
            ],
        ]);
        $audit->expects($this->once())->method('log');

        $mw = new AuthMiddleware($auth, $audit);

        $request = makeRequest('GET', '/', null, null, ['Authorization' => 'Bearer token']);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                TestCase::assertSame(1, $request->getAttribute('user_id'));
                TestCase::assertSame('550e8400-e29b-41d4-a716-446655440001', $request->getAttribute('user_uuid'));
                TestCase::assertSame('a@b.com', $request->getAttribute('user_email'));
                TestCase::assertSame('user', $request->getAttribute('user_role'));
                TestCase::assertSame(
                    '550e8400-e29b-41d4-a716-446655440001',
                    $request->getAttribute('authenticated_user')['uuid'] ?? null
                );

                $resp = new \Slim\Psr7\Response();
                $resp->getBody()->write('ok');
                return $resp;
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testProcessWithMissingHeader(): void
    {
        $auth = $this->createMock(AuthService::class);
        $audit = $this->createMock(AuditLogService::class);
        $mw = new AuthMiddleware($auth, $audit);
        $request = makeRequest('GET', '/');
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $resp = $mw->process($request, $handler);
        $this->assertEquals(401, $resp->getStatusCode());
    }
}


