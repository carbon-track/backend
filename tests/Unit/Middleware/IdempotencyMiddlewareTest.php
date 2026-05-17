<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Middleware\IdempotencyMiddleware;
use CarbonTrack\Models\IdempotencyRecord;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\DatabaseService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

class IdempotencyMiddlewareTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        if (self::$capsule !== null) {
            return;
        }
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('idempotency_records', function (Blueprint $t) {
            $t->increments('id');
            $t->string('idempotency_key', 36);
            $t->string('composite_key', 64)->nullable();
            $t->integer('user_id')->nullable();
            $t->string('request_method', 10);
            $t->string('request_uri', 512);
            $t->text('request_body')->nullable();
            $t->integer('response_status');
            $t->text('response_body');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->timestamps();
            $t->index(['idempotency_key', 'user_id'], 'idx_idempotency_key_user');
            $t->unique(['idempotency_key', 'composite_key', 'user_id'], 'uniq_idempotency_key_composite_user');
        });

        self::$capsule = $capsule;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$capsule !== null) {
            self::$capsule->table('idempotency_records')->delete();
        }
    }

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
        // Sanity: the row should now be persisted with composite_key + user_id
        $this->assertSame(1, IdempotencyRecord::query()->count());
    }

    public function testStoredClientIpIgnoresForwardedHeadersFromUntrustedRemote(): void
    {
        $previousTrustedProxies = $_ENV['TRUSTED_PROXY_CIDRS'] ?? null;
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        unset($_ENV['TRUSTED_PROXY_CIDRS']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        try {
            $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
            $logger = $this->createMock(\Monolog\Logger::class);
            $mw = new IdempotencyMiddleware($db, $logger);

            $request = makeRequest('POST', '/api/v1/exchange', ['a' => 1], null, [
                'X-Request-ID' => ['123e4567-e89b-12d3-a456-426614174010'],
                'X-Forwarded-For' => ['203.0.113.200'],
            ]);
            $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $resp = new \Slim\Psr7\Response(200);
                    $resp->getBody()->write('{"ok":true}');
                    return $resp->withHeader('Content-Type', 'application/json');
                }
            };

            $mw->process($request, $handler);

            $record = IdempotencyRecord::query()->first();
            $this->assertSame('198.51.100.10', $record->ip_address);
        } finally {
            if ($previousTrustedProxies === null) {
                unset($_ENV['TRUSTED_PROXY_CIDRS']);
            } else {
                $_ENV['TRUSTED_PROXY_CIDRS'] = $previousTrustedProxies;
            }
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }
    }

    public function testStoredClientIpUsesFirstUntrustedForwardedAddressFromTrustedProxyChain(): void
    {
        $previousTrustedProxies = $_ENV['TRUSTED_PROXY_CIDRS'] ?? null;
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_ENV['TRUSTED_PROXY_CIDRS'] = '198.51.100.0/24';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        try {
            $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
            $logger = $this->createMock(\Monolog\Logger::class);
            $mw = new IdempotencyMiddleware($db, $logger);

            $request = makeRequest('POST', '/api/v1/exchange', ['a' => 1], null, [
                'X-Request-ID' => ['123e4567-e89b-12d3-a456-426614174011'],
                'X-Forwarded-For' => ['203.0.113.250, 203.0.113.77, 198.51.100.20'],
            ]);
            $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $resp = new \Slim\Psr7\Response(200);
                    $resp->getBody()->write('{"ok":true}');
                    return $resp->withHeader('Content-Type', 'application/json');
                }
            };

            $mw->process($request, $handler);

            $record = IdempotencyRecord::query()->first();
            $this->assertSame('203.0.113.77', $record->ip_address);
        } finally {
            if ($previousTrustedProxies === null) {
                unset($_ENV['TRUSTED_PROXY_CIDRS']);
            } else {
                $_ENV['TRUSTED_PROXY_CIDRS'] = $previousTrustedProxies;
            }
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }
    }

    public function testReplayingSameUuidAcrossUsersDoesNotShareCachedResponse(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174001';

        $invocationCount = 0;
        $handlerFor = function (string $body) use (&$invocationCount) {
            return new class($body, $invocationCount) implements \Psr\Http\Server\RequestHandlerInterface {
                public function __construct(private string $body, public int &$invocationCount)
                {
                }
                public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
                {
                    $this->invocationCount++;
                    $resp = new \Slim\Psr7\Response(200);
                    $resp->getBody()->write($this->body);
                    return $resp->withHeader('Content-Type', 'application/json');
                }
            };
        };

        // User A submits with UUID + body{"a":1}; gets back response A.
        $userARequest = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 101);
        $handlerA = $handlerFor('{"resp":"A","secret":"only-for-A"}');
        $respA = $mw->process($userARequest, $handlerA);
        $this->assertSame(200, $respA->getStatusCode());
        $bodyA = (string) $respA->getBody();
        $this->assertStringContainsString('only-for-A', $bodyA);

        // User B submits the exact same UUID + same body but as a different user.
        // Must NOT see user A's cached body, must run the handler again.
        $userBRequest = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 202);
        $handlerB = $handlerFor('{"resp":"B"}');
        $respB = $mw->process($userBRequest, $handlerB);
        $this->assertSame(200, $respB->getStatusCode());
        $bodyB = (string) $respB->getBody();
        $this->assertStringContainsString('"B"', $bodyB);
        $this->assertStringNotContainsString('only-for-A', $bodyB);
        $this->assertNotSame('true', $respB->getHeaderLine('X-Idempotent-Replay'));
    }

    public function testBearerTokenIdentityIsUsedWhenAuthMiddlewareHasNotRunYet(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $authService = $this->getMockBuilder(AuthService::class)->disableOriginalConstructor()->getMock();
        $authService->method('validateToken')->willReturnCallback(function (string $token): array {
            return match ($token) {
                'token-a' => ['user_id' => 101, 'user' => ['id' => 101]],
                'token-b' => ['user_id' => 202, 'user' => ['id' => 202]],
                default => throw new \RuntimeException('Invalid token'),
            };
        });
        $mw = new IdempotencyMiddleware($db, $logger, $authService);

        $uuid = '123e4567-e89b-12d3-a456-426614174005';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headersA = ['X-Request-ID' => [$uuid], 'Authorization' => ['Bearer token-a']];
        $headersB = ['X-Request-ID' => [$uuid], 'Authorization' => ['Bearer token-b']];
        $first = makeRequest('POST', '/api/v1/carbon-track/record', ['amount' => 1], null, $headersA);
        $second = makeRequest('POST', '/api/v1/carbon-track/record', ['amount' => 1], null, $headersB);

        $mw->process($first, $handler);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertNotSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame(2, IdempotencyRecord::query()->count());
    }

    public function testBearerTokenNestedUserIdIsWrittenToRequestAttribute(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $authService = $this->getMockBuilder(AuthService::class)->disableOriginalConstructor()->getMock();
        $authService->method('validateToken')->willReturn([
            'user' => ['id' => 303],
            'uuid' => '550e8400-e29b-41d4-a716-446655443303',
            'email' => 'nested@example.com',
            'role' => 'user',
        ]);
        $mw = new IdempotencyMiddleware($db, $logger, $authService);

        $seenUserId = null;
        $handler = new class($seenUserId) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public ?int &$seenUserId)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->seenUserId = $request->getAttribute('user_id');
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write('{"ok":true}');
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $request = makeRequest('POST', '/api/v1/carbon-track/record', ['amount' => 1], null, [
            'X-Request-ID' => ['123e4567-e89b-12d3-a456-426614174014'],
            'Authorization' => ['Bearer nested-token'],
        ]);

        $mw->process($request, $handler);

        $this->assertSame(303, $seenUserId);
        $this->assertSame(303, IdempotencyRecord::query()->first()?->user_id);
    }

    public function testCanonicalCarbonRecordsEndpointIsIdempotent(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174015';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(201);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $first = makeRequest('POST', '/api/v1/carbon-records', ['amount' => 1], null, $headers)
            ->withAttribute('user_id', 123);
        $retry = makeRequest('POST', '/api/v1/carbon-records', ['amount' => 1], null, $headers)
            ->withAttribute('user_id', 123);

        $mw->process($first, $handler);
        $respRetry = $mw->process($retry, $handler);

        $this->assertSame(1, $callCounter);
        $this->assertSame('true', $respRetry->getHeaderLine('X-Idempotent-Replay'));
    }

    public function testSameUserReplayingSameUuidStillReturnsCachedResponse(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174002';

        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(201);
                $resp->getBody()->write('{"resp":"first-only"}');
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $first = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 999);
        $resp1 = $mw->process($first, $handler);

        $second = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 999);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(201, $resp1->getStatusCode());
        $this->assertSame(201, $resp2->getStatusCode());
        $this->assertSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame(1, $callCounter, 'Handler should run only once when same user replays same UUID');
        $this->assertStringContainsString('first-only', (string) $resp2->getBody());
    }

    public function testServerErrorResponsesAreNotCached(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174009';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(500);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $first = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 999);
        $second = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, [
            'X-Request-ID' => [$uuid],
        ])->withAttribute('user_id', 999);

        $mw->process($first, $handler);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertNotSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame(0, IdempotencyRecord::query()->count());
    }

    public function testTransientClientErrorsAreNotCached(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174012';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(429);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $first = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, $headers)
            ->withAttribute('user_id', 999);
        $second = makeRequest('POST', '/api/v1/messages/broadcast', ['a' => 1], null, $headers)
            ->withAttribute('user_id', 999);

        $mw->process($first, $handler);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertNotSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame(0, IdempotencyRecord::query()->count());
    }

    public function testSameUserCanReplayEarlierPayloadAfterUuidWasUsedForDifferentBody(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174003';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $body = $request->getParsedBody();
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write(json_encode(['variant' => $body['variant'] ?? 'unknown']));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $firstB = makeRequest('POST', '/api/v1/messages/broadcast', ['variant' => 'B'], null, $headers)
            ->withAttribute('user_id', 777);
        $firstA = makeRequest('POST', '/api/v1/messages/broadcast', ['variant' => 'A'], null, $headers)
            ->withAttribute('user_id', 777);
        $retryA = makeRequest('POST', '/api/v1/messages/broadcast', ['variant' => 'A'], null, $headers)
            ->withAttribute('user_id', 777);

        $mw->process($firstB, $handler);
        $respA1 = $mw->process($firstA, $handler);
        $respA2 = $mw->process($retryA, $handler);

        $this->assertSame(2, $callCounter, 'Retrying the second payload should replay its matching composite row');
        $this->assertNotSame('true', $respA1->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame('true', $respA2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertStringContainsString('"A"', (string) $respA2->getBody());
    }

    public function testSamePayloadWithDifferentRequestIdsEachGetsCached(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $payload = ['amount' => 1, 'meta' => ['b' => 2, 'a' => 1]];
        $uuidA = '123e4567-e89b-12d3-a456-426614174006';
        $uuidB = '123e4567-e89b-12d3-a456-426614174007';
        $requestA = makeRequest('POST', '/api/v1/carbon-track/record', $payload, null, [
            'X-Request-ID' => [$uuidA],
        ])->withAttribute('user_id', 123);
        $requestB = makeRequest('POST', '/api/v1/carbon-track/record', $payload, null, [
            'X-Request-ID' => [$uuidB],
        ])->withAttribute('user_id', 123);
        $retryB = makeRequest('POST', '/api/v1/carbon-track/record', $payload, null, [
            'X-Request-ID' => [$uuidB],
        ])->withAttribute('user_id', 123);

        $mw->process($requestA, $handler);
        $mw->process($requestB, $handler);
        $respRetryB = $mw->process($retryB, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertSame('true', $respRetryB->getHeaderLine('X-Idempotent-Replay'));
        $this->assertSame(2, IdempotencyRecord::query()->count());
    }

    public function testAssociativePayloadOrderDoesNotChangeCompositeFingerprint(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174008';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write('{"ok":true}');
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $first = makeRequest('POST', '/api/v1/carbon-track/record', [
            'meta' => ['b' => 2, 'a' => 1],
        ], null, $headers)->withAttribute('user_id', 321);
        $retry = makeRequest('POST', '/api/v1/carbon-track/record', [
            'meta' => ['a' => 1, 'b' => 2],
        ], null, $headers)->withAttribute('user_id', 321);

        $mw->process($first, $handler);
        $respRetry = $mw->process($retry, $handler);

        $this->assertSame(1, $callCounter);
        $this->assertSame('true', $respRetry->getHeaderLine('X-Idempotent-Replay'));
    }

    public function testLongStringsWithSamePrefixButDifferentSuffixUseDifferentCompositeKeys(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174016';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(200);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $prefix = str_repeat('a', 8192);
        $first = makeRequest('POST', '/api/v1/carbon-track/record', [
            'note' => $prefix . 'x',
        ], null, $headers)->withAttribute('user_id', 321);
        $second = makeRequest('POST', '/api/v1/carbon-track/record', [
            'note' => $prefix . 'y',
        ], null, $headers)->withAttribute('user_id', 321);

        $mw->process($first, $handler);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertNotSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
    }

    public function testMultipartUploadsWithDifferentFilesUseDifferentCompositeKeys(): void
    {
        $db = $this->getMockBuilder(DatabaseService::class)->disableOriginalConstructor()->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $mw = new IdempotencyMiddleware($db, $logger);

        $uuid = '123e4567-e89b-12d3-a456-426614174004';
        $callCounter = 0;
        $handler = new class($callCounter) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(public int &$count)
            {
            }
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->count++;
                $resp = new \Slim\Psr7\Response(201);
                $resp->getBody()->write(json_encode(['count' => $this->count]));
                return $resp->withHeader('Content-Type', 'application/json');
            }
        };

        $headers = ['X-Request-ID' => [$uuid]];
        $first = makeRequest('POST', '/api/v1/carbon-track/record', ['amount' => 1], null, $headers)
            ->withAttribute('user_id', 123)
            ->withUploadedFiles(['proof' => $this->makeUploadedFile('proof-a.png', 'image-a')]);
        $second = makeRequest('POST', '/api/v1/carbon-track/record', ['amount' => 1], null, $headers)
            ->withAttribute('user_id', 123)
            ->withUploadedFiles(['proof' => $this->makeUploadedFile('proof-a.png', 'image-b')]);

        $mw->process($first, $handler);
        $resp2 = $mw->process($second, $handler);

        $this->assertSame(2, $callCounter);
        $this->assertNotSame('true', $resp2->getHeaderLine('X-Idempotent-Replay'));
        $this->assertStringContainsString('"count":2', (string) $resp2->getBody());
    }

    private function makeUploadedFile(string $clientFilename, string $contents): \Psr\Http\Message\UploadedFileInterface
    {
        return new class($clientFilename, $contents) implements \Psr\Http\Message\UploadedFileInterface {
            private \Slim\Psr7\Stream $stream;

            public function __construct(private string $clientFilename, string $contents)
            {
                $resource = fopen('php://temp', 'r+');
                fwrite($resource, $contents);
                rewind($resource);
                $this->stream = new \Slim\Psr7\Stream($resource);
            }

            public function getStream(): \Psr\Http\Message\StreamInterface
            {
                return $this->stream;
            }

            public function moveTo($targetPath): void
            {
                throw new \RuntimeException('Not needed for tests');
            }

            public function getSize(): ?int
            {
                return $this->stream->getSize();
            }

            public function getError(): int
            {
                return UPLOAD_ERR_OK;
            }

            public function getClientFilename(): ?string
            {
                return $this->clientFilename;
            }

            public function getClientMediaType(): ?string
            {
                return 'image/png';
            }
        };
    }
}


