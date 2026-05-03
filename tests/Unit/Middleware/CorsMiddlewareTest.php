<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\CorsMiddleware;

class CorsMiddlewareTest extends TestCase
{
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'APP_ENV',
            'CORS_ALLOWED_ORIGINS',
            'CORS_ALLOWED_METHODS',
            'CORS_ALLOWED_HEADERS',
            'CORS_EXPOSE_HEADERS',
            'CORS_ALLOW_CREDENTIALS',
            'FRONTEND_URL',
        ] as $key) {
            $this->previousEnv[$key] = [
                'exists' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $state) {
            if ($state['exists']) {
                $_ENV[$key] = $state['value'];
            } else {
                unset($_ENV[$key]);
            }
        }

        parent::tearDown();
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(CorsMiddleware::class));
    }

    public function testPreflightOptionsAddsHeadersAnd200(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
        $_ENV['CORS_ALLOWED_METHODS'] = 'GET,POST,PUT,DELETE,OPTIONS';
        $_ENV['CORS_ALLOWED_HEADERS'] = 'Content-Type,Authorization,X-Request-ID';
        $_ENV['CORS_ALLOW_CREDENTIALS'] = 'false';

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
        $this->assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
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

    public function testWildcardAllowedOriginSetsAllowOriginHeader(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://*.example.com';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://admin.example.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertEquals('https://admin.example.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWildcardAllowedOriginMatchesNestedSubdomains(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://*.example.com';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://dev.admin.example.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertEquals('https://dev.admin.example.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWildcardAllowedOriginMatchesRootDomain(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://*.example.com';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://example.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertEquals('https://example.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginWithCredentialsDoesNotReflectArbitraryOrigin(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['CORS_ALLOWED_ORIGINS'] = '*';
        $_ENV['CORS_ALLOW_CREDENTIALS'] = 'true';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://evil.example']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertSame('', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testMissingAllowedOriginsKeepsWildcardWithoutCredentials(): void
    {
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        unset($_ENV['CORS_ALLOW_CREDENTIALS']);
        unset($_ENV['FRONTEND_URL']);

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://any.example']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testFrontendUrlFallbackIsNormalizedForCredentialedOrigin(): void
    {
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        $_ENV['FRONTEND_URL'] = 'https://app.example.com/admin/';
        $_ENV['CORS_ALLOW_CREDENTIALS'] = 'true';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://app.example.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return new \Slim\Psr7\Response(204);
            }
        };

        $resp = $mw->process($request, $handler);

        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertSame('https://app.example.com', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $resp->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testExistingVaryHeaderIsPreserved(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://a.com';

        $mw = new CorsMiddleware();
        $request = makeRequest('GET', '/api/v1/ping', null, null, [
            'Origin' => ['https://a.com']
        ]);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                return (new \Slim\Psr7\Response(204))->withHeader('Vary', 'Accept-Encoding');
            }
        };

        $resp = $mw->process($request, $handler);

        $vary = $resp->getHeaderLine('Vary');
        $this->assertStringContainsString('Accept-Encoding', $vary);
        $this->assertStringContainsString('Origin', $vary);
    }
}


