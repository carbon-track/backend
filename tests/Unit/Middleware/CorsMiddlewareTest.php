<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\CorsMiddleware;

class CorsMiddlewareTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(CorsMiddleware::class));
    }

    public function testPreflightOptionsAddsHeadersAnd200(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
        $_ENV['CORS_ALLOWED_METHODS'] = 'GET,POST,PUT,DELETE,OPTIONS';
        $_ENV['CORS_ALLOWED_HEADERS'] = 'Content-Type,Authorization,X-Request-ID';

        $mw = new CorsMiddleware();
        $request = makeRequest('OPTIONS', '/api/v1/ping', null, null, [
            'Origin' => ['https://example.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response();
            }
        };

        $resp = $mw->process($request, $handler);
        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertNotEmpty($resp->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertNotEmpty($resp->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('true', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testGetWithAllowedOriginSetsAllowOriginHeader(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://a.com,https://b.com';
        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://a.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                $r = new \Slim\Psr7\Response(204);
                return $r;
            }
        };
        $resp = $mw->process($request, $handler);
        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertEquals('https://a.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }
}


