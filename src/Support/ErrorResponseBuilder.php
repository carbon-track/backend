<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class ErrorResponseBuilder
{
    public static function build(
        Throwable $exception,
        ServerRequestInterface $request,
        string $environment,
        int $status = 500
    ): array {
        $env = strtolower($environment);
        $isProduction = $env === 'production';

        $payload = [
            'success' => false,
            'code' => self::resolveErrorCode($exception, $status),
            'request_id' => self::extractRequestId($request),
        ];

        if (!$isProduction) {
            $payload['message'] = $exception->getMessage();
            $payload['error'] = get_class($exception);
        }

        return $payload;
    }

    private static function resolveErrorCode(Throwable $exception, int $status): string
    {
        $code = $exception->getCode();

        if (is_string($code) && $code !== '') {
            return $code;
        }

        if (is_int($code) && $code > 0) {
            return (string) $code;
        }

        return $status >= 500 ? 'SERVER_ERROR' : (string) $status;
    }

    private static function extractRequestId(ServerRequestInterface $request): ?string
    {
        $headerRequestId = $request->getHeaderLine('X-Request-ID');
        if ($headerRequestId !== '') {
            return $headerRequestId;
        }

        $serverParams = $request->getServerParams();
        $candidateKeys = ['HTTP_X_REQUEST_ID', 'REQUEST_ID'];

        foreach ($candidateKeys as $key) {
            if (!empty($serverParams[$key])) {
                return $serverParams[$key];
            }
        }

        return null;
    }
}
