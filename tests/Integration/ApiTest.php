<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use DI\Container;

class ApiTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test app instance
        $container = new Container();
        AppFactory::setContainer($container);
        $this->app = AppFactory::create();

        // Add basic health check route for testing
        $this->app->get('/', function ($request, $response) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'CarbonTrack API is running',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Add test routes
        $this->app->get('/test/ping', function ($request, $response) {
            $response->getBody()->write(json_encode(['pong' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $this->app->post('/test/echo', function ($request, $response) {
            $data = $request->getParsedBody();
            $response->getBody()->write(json_encode([
                'echo' => $data,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });
    }

    public function testHealthCheckEndpoint(): void
    {
        $request = $this->createRequest('GET', '/');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['success']);
        $this->assertEquals('CarbonTrack API is running', $data['message']);
        $this->assertEquals('1.0.0', $data['version']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testPingEndpoint(): void
    {
        $request = $this->createRequest('GET', '/test/ping');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['pong']);
    }

    public function testEchoEndpoint(): void
    {
        $testData = [
            'message' => 'Hello, World!',
            'number' => 42,
            'array' => [1, 2, 3]
        ];

        $request = $this->createRequest('POST', '/test/echo', $testData);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertEquals($testData, $data['echo']);
        $this->assertEquals('POST', $data['method']);
        $this->assertTrue(strpos($data['uri'], '/test/echo') !== false);
    }

    public function testNotFoundEndpoint(): void
    {
        try {
            $request = $this->createRequest('GET', '/nonexistent');
            $response = $this->app->handle($request);
            $this->assertNotEquals(200, $response->getStatusCode());
        } catch (\Throwable $e) {
            $this->assertTrue(stripos($e->getMessage(), 'not found') !== false);
        }
    }

    public function testMethodNotAllowed(): void
    {
        // Try to POST to a GET-only endpoint; Slim may throw MethodNotAllowed exception.
        try {
            $request = $this->createRequest('POST', '/test/ping');
            $response = $this->app->handle($request);
            $this->assertNotEquals(200, $response->getStatusCode());
        } catch (\Throwable $e) {
            $this->assertTrue(stripos($e->getMessage(), 'not allowed') !== false);
        }
    }

    private function createRequest(string $method, string $uri, array $data = [])
    {
        $serverRequestFactory = new ServerRequestFactory();
        $request = $serverRequestFactory->createServerRequest($method, $uri);

        if (!empty($data)) {
            $request = $request->withParsedBody($data);
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $request;
    }
}

