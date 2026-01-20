<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\ErrorResponseBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class ErrorResponseBuilderTest extends TestCase
{
    public function testDevelopmentEnvironmentContainsMessage(): void
    {
        $exception = new \RuntimeException('boom', 123);
        $request = $this->makeRequest(['REQUEST_ID' => 'abc123']);

        $payload = ErrorResponseBuilder::build($exception, $request, 'development', 500);

        $this->assertFalse($payload['success']);
        $this->assertSame('123', $payload['code']);
        $this->assertSame('boom', $payload['message']);
        $this->assertSame(\RuntimeException::class, $payload['error']);
        $this->assertSame('abc123', $payload['request_id']);
    }

    public function testProductionEnvironmentOmitsMessage(): void
    {
        $exception = new \RuntimeException('boom');
        $request = $this->makeRequest([], ['X-Request-ID' => ['req-001']]);

        $payload = ErrorResponseBuilder::build($exception, $request, 'production', 500);

        $this->assertArrayNotHasKey('message', $payload);
        $this->assertArrayNotHasKey('error', $payload);
        $this->assertSame('SERVER_ERROR', $payload['code']);
        $this->assertSame('req-001', $payload['request_id']);
    }

    public function testStatusDrivesDefaultErrorCodeForNonServerErrors(): void
    {
        $exception = new \RuntimeException('Not allowed', 0);
        $payload = ErrorResponseBuilder::build($exception, $this->makeRequest(), 'production', 403);

        $this->assertSame('403', $payload['code']);
    }

    public function testRequestAttributeTakesPriorityAndNormalizesUuid(): void
    {
        $exception = new \RuntimeException('boom');
        $request = $this->makeRequest(['REQUEST_ID' => 'server-id'], ['X-Request-ID' => ['REQ-001']]);
        $request = $request->withAttribute('request_id', '550E8400-E29B-41D4-A716-446655440001');

        $payload = ErrorResponseBuilder::build($exception, $request, 'production', 500);

        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $payload['request_id']);
    }

    public function testRequestIdFallsBackToHeaderWhenAttributeBlank(): void
    {
        $exception = new \RuntimeException('boom');
        $request = $this->makeRequest([], ['X-Request-ID' => ['REQ-001']]);
        $request = $request->withAttribute('request_id', '   ');

        $payload = ErrorResponseBuilder::build($exception, $request, 'production', 500);

        $this->assertSame('REQ-001', $payload['request_id']);
    }

    public function testRequestIdFallsBackToServerParamWhenHeaderBlank(): void
    {
        $exception = new \RuntimeException('boom');
        $request = $this->makeRequest(
            ['HTTP_X_REQUEST_ID' => '550E8400-E29B-61D4-A716-446655440001'],
            ['X-Request-ID' => ['   ']]
        );

        $payload = ErrorResponseBuilder::build($exception, $request, 'production', 500);

        $this->assertSame('550E8400-E29B-61D4-A716-446655440001', $payload['request_id']);
    }

    private function makeRequest(array $serverParams = [], array $headers = []): Request
    {
        $uri = new Uri('https', 'example.com', null, '/test');
        $stream = new Stream(fopen('php://temp', 'r+'));
        $slimHeaders = new Headers($headers);

        return new Request('GET', $uri, $slimHeaders, [], $serverParams, $stream);
    }
}
