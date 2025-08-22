<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\LoggingMiddleware;

class LoggingMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(LoggingMiddleware::class));
    }

    public function testLogsRequestAndResponse(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');
        $mw = new LoggingMiddleware($logger);

        $request = makeRequest('GET', '/health');
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(204, $resp->getStatusCode());
    }

    public function testLogsErrorWhenHandlerThrows(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $mw = new LoggingMiddleware($logger);

        $request = makeRequest('GET', '/boom');
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                throw new \RuntimeException('fail');
            }
        };

        $this->expectException(\RuntimeException::class);
        $mw->process($request, $handler);
    }
}


