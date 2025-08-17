<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\AdminMiddleware;
use CarbonTrack\Services\AuthService;

class AdminMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(AdminMiddleware::class));
    }

    public function testRejectsWhenNotAuthenticated(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);
        $mw = new AdminMiddleware($auth);

        $request = makeRequest('GET', '/');
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $resp = $mw->process($request, $handler);
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function testRejectsWhenNotAdmin(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'is_admin'=>0]);
        $auth->method('isAdminUser')->willReturn(false);
        $mw = new AdminMiddleware($auth);

        $request = makeRequest('GET', '/');
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $resp = $mw->process($request, $handler);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testPassThroughWhenAdmin(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'is_admin'=>1]);
        $auth->method('isAdminUser')->willReturn(true);

        $mw = new AdminMiddleware($auth);
        $request = makeRequest('GET', '/');
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


