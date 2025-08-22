<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\IdempotencyMiddleware;
use CarbonTrack\Services\DatabaseService;

class IdempotencyMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(IdempotencyMiddleware::class));
    }

    public function testMissingRequestIdReturns400(): void
    {
        // DatabaseService not used directly in current implementation; pass a dummy stub
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $request = makeRequest('POST', '/api/v1/auth/register');
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $resp = $mw->process($request, $handler);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testPassthroughWhenNotSensitive(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $request = makeRequest('POST', '/api/v1/others');
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $resp = new \Slim\Psr7\Response();
                $resp->getBody()->write('{"ok":true}');
                return $resp->withHeader('Content-Type','application/json');
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testInvalidRequestIdFormatReturns400(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $request = makeRequest('POST', '/api/v1/exchange', null, null, [
            'X-Request-ID' => ['not-a-uuid']
        ]);
        $handler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $resp = $mw->process($request, $handler);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testSensitiveWithValidUuidPassesThroughAndStoresSafely(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $request = makeRequest('POST', '/api/v1/exchange', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
            'User-Agent' => ['PHPUnit']
        ]);

        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $resp = new \Slim\Psr7\Response(201);
                $resp->getBody()->write('{"success":true}');
                return $resp->withHeader('Content-Type','application/json');
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(201, $resp->getStatusCode());
        $this->assertEquals('application/json', $resp->getHeaderLine('Content-Type'));
        // When storage fails, middleware swallows error and still returns handler response
        // We can't assert DB writes here without wiring Eloquent; this verifies happy path passthrough.
    }
}


