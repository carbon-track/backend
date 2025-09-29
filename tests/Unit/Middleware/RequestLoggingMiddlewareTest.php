<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use CarbonTrack\Middleware\RequestLoggingMiddleware;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\SystemLogService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class RequestLoggingMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testInjectsUuidWhenMissing(): void
    {
        $systemLog = $this->createMock(SystemLogService::class);
        $systemLog->expects($this->never())->method('log');
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(null);
        $logger = $this->createMock(Logger::class);

        $middleware = new RequestLoggingMiddleware($systemLog, $authService, $logger);

        $handler = new class implements RequestHandlerInterface {
            public ?string $header = null;
            public ?string $attribute = null;

            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->header = $request->getHeaderLine('X-Request-ID');
                $this->attribute = $request->getAttribute('request_id');
                return new Response(200);
            }
        };

        $request = makeRequest('POST', '/');
        $response = $middleware->process($request, $handler);

        $this->assertNotEmpty($handler->header);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $handler->header);
        $this->assertSame($handler->header, $handler->attribute);
        $this->assertSame($handler->header, $response->getHeaderLine('X-Request-ID'));
        $this->assertSame($handler->header, $_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testReplacesInvalidRequestIdWithUuid(): void
    {
        $systemLog = $this->createMock(SystemLogService::class);
        $systemLog->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $context): bool {
                return isset($context['request_id'])
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $context['request_id']) === 1;
            }));

        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(null);
        $logger = $this->createMock(Logger::class);

        $middleware = new RequestLoggingMiddleware($systemLog, $authService, $logger);

        $handler = new class implements RequestHandlerInterface {
            public ?string $header = null;
            public ?string $attribute = null;

            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->header = $request->getHeaderLine('X-Request-ID');
                $this->attribute = $request->getAttribute('request_id');
                return new Response(201);
            }
        };

        $request = makeRequest('POST', '/api/v1/admin/messages/broadcast', null, null, [
            'X-Request-ID' => ['not-a-uuid'],
            'User-Agent' => ['PHPUnit']
        ]);

        $response = $middleware->process($request, $handler);

        $this->assertNotSame('not-a-uuid', $handler->header);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $handler->header);
        $this->assertSame($handler->header, $handler->attribute);
        $this->assertSame($handler->header, $response->getHeaderLine('X-Request-ID'));
        $this->assertSame($handler->header, $_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testPreservesValidRequestId(): void
    {
        $systemLog = $this->createMock(SystemLogService::class);
        $systemLog->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $context): bool {
                return ($context['request_id'] ?? null) === '123e4567-e89b-12d3-a456-426614174000';
            }));

        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 42]);
        $logger = $this->createMock(Logger::class);

        $middleware = new RequestLoggingMiddleware($systemLog, $authService, $logger);

        $handler = new class implements RequestHandlerInterface {
            public ?string $header = null;
            public ?string $attribute = null;

            public function handle(ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->header = $request->getHeaderLine('X-Request-ID');
                $this->attribute = $request->getAttribute('request_id');
                return new Response(200);
            }
        };

        $original = '123E4567-E89B-12D3-A456-426614174000';
        $request = makeRequest('POST', '/api/v1/admin/messages/broadcast', null, null, [
            'X-Request-ID' => [$original],
            'User-Agent' => ['PHPUnit']
        ]);

        $response = $middleware->process($request, $handler);

        $this->assertSame('123e4567-e89b-12d3-a456-426614174000', $handler->header);
        $this->assertSame($handler->header, $handler->attribute);
        $this->assertSame($handler->header, $response->getHeaderLine('X-Request-ID'));
        $this->assertSame($handler->header, $_SERVER['HTTP_X_REQUEST_ID']);
    }
}
