<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\TurnstileMiddleware;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\AuditLogService;

class TurnstileMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(TurnstileMiddleware::class));
    }

    public function testProtectedRouteMissingToken(): void
    {
        $svc = $this->createMock(TurnstileService::class);
        $audit = $this->createMock(AuditLogService::class);
        $mw = new TurnstileMiddleware($svc, $audit);

        $request = makeRequest('POST', '/api/v1/auth/login');
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $_ENV['APP_ENV'] = 'production';
        $resp = $mw->process($request, $handler);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testProtectedRouteVerified(): void
    {
        $svc = $this->createMock(TurnstileService::class);
        $audit = $this->createMock(AuditLogService::class);
        $mw = new TurnstileMiddleware($svc, $audit);

        $svc->method('verify')->willReturn(['success' => true]);
        $_ENV['APP_ENV'] = 'production';

        $request = makeRequest('POST', '/api/v1/auth/login', ['cf_turnstile_response' => 'token']);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $resp = new \Slim\Psr7\Response();
                $resp->getBody()->write('ok');
                return $resp;
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(200, $resp->getStatusCode());
    }
}


