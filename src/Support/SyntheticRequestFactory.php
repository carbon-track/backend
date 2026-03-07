<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

class SyntheticRequestFactory
{
    public static function fromContext(
        ?string $path = '/',
        string $method = 'GET',
        ?string $requestId = null,
        array $queryParams = [],
        mixed $parsedBody = null,
        array $serverParams = []
    ): ServerRequestInterface {
        $normalizedPath = self::normalizePath($path);
        $headers = [];

        if (is_string($requestId) && $requestId !== '') {
            $headers['X-Request-ID'] = [$requestId];
            $serverParams['HTTP_X_REQUEST_ID'] = $requestId;
            $serverParams['REQUEST_ID'] = $requestId;
        }

        $serverParams['REQUEST_METHOD'] = strtoupper($method);
        $serverParams['REQUEST_URI'] = $normalizedPath;
        $serverParams['SCRIPT_NAME'] = $normalizedPath;

        $uri = new Uri('http', 'localhost', null, $normalizedPath);
        $stream = new Stream(fopen('php://temp', 'r+'));
        $request = new Request(strtoupper($method), $uri, new Headers($headers), [], $serverParams, $stream);
        $request = $request->withQueryParams($queryParams);

        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }

        if (is_string($requestId) && $requestId !== '') {
            $request = $request->withAttribute('request_id', $requestId);
        }

        return $request;
    }

    private static function normalizePath(?string $path): string
    {
        $candidate = trim((string) $path);
        if ($candidate === '') {
            return '/';
        }

        if (preg_match('#^https?://#i', $candidate) === 1) {
            $parts = parse_url($candidate);
            $candidate = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        }

        if ($candidate[0] !== '/') {
            $candidate = '/' . $candidate;
        }

        return $candidate;
    }
}