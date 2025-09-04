<?php

declare(strict_types=1);

namespace CarbonTrack\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Read config from env with sensible defaults
        $allowedOriginsEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*';
        $allowedMethods = $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS';
    // 默认允许常见自定义头，覆盖时使用 CORS_ALLOWED_HEADERS
    $allowedHeadersDefault = $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Authorization,X-Request-ID,X-Requested-With,X-Turnstile-Token';
    $exposeHeaders = $_ENV['CORS_EXPOSE_HEADERS'] ?? 'Content-Type,Authorization,X-Request-ID';
        $allowCredentials = filter_var($_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

        // Parse and trim allowed origins list, and add localhost for dev env
        $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsEnv))));
        if (($_ENV['APP_ENV'] ?? 'production') !== 'production') {
            $localOrigins = [
                'http://localhost:5173',
                'http://localhost:3000',
                'http://127.0.0.1:5173',
                'http://127.0.0.1:3000'
            ];
            $allowedOrigins = array_unique(array_merge($allowedOrigins, $localOrigins));
        }

        $origin = $request->getHeaderLine('Origin');
        $method = strtoupper($request->getMethod());

        // Helper to check wildcard origins like https://*.example.com
        $isOriginAllowed = function (?string $origin) use ($allowedOrigins): bool {
            if (!$origin) {
                return false;
            }
            // 允许特殊的 "null" 源（如 file:// 场景）当配置为通配或显式包含 null
            if ($origin === 'null') {
                foreach ($allowedOrigins as $allowed) {
                    if ($allowed === '*' || strcasecmp($allowed, 'null') === 0) {
                        return true;
                    }
                }
                return false;
            }
            foreach ($allowedOrigins as $allowed) {
                if ($allowed === '*') {
                    return true;
                }
                if (strcasecmp($allowed, $origin) === 0) {
                    return true;
                }
                // Wildcard subdomain match: https://*.example.com
                if (strpos($allowed, '*.') !== false) {
                    $pattern = '/^' . str_replace(['*.', '.', '/'], ['([^.]+)\.', '\\.', '\/'], preg_quote($allowed, '/')) . '$/i';
                    if (preg_match($pattern, $origin)) {
                        return true;
                    }
                }
            }
            return false;
        };

        // Determine headers for CORS (used for both preflight and actual responses)
        $varyValues = ['Origin'];
        $headersToSet = [
            'Access-Control-Allow-Methods' => $allowedMethods,
            'Access-Control-Expose-Headers' => $exposeHeaders,
            'Access-Control-Max-Age' => '86400',
        ];

        // Access-Control-Allow-Headers: echo request headers if present, else use default
        $requestHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        if ($requestHeaders) {
            $headersToSet['Access-Control-Allow-Headers'] = $requestHeaders;
            $varyValues[] = 'Access-Control-Request-Headers';
        } else {
            $headersToSet['Access-Control-Allow-Headers'] = $allowedHeadersDefault;
        }

        // Allow-Origin logic considering credentials
        if ($isOriginAllowed($origin)) {
            $headersToSet['Access-Control-Allow-Origin'] = $origin;
            if ($allowCredentials) {
                $headersToSet['Access-Control-Allow-Credentials'] = 'true';
            }
        } else {
            // If no specific Origin header or not allowed:
            // only set wildcard when credentials are not required
            if (in_array('*', $allowedOrigins, true) && !$allowCredentials) {
                $headersToSet['Access-Control-Allow-Origin'] = '*';
            }
        }

        // Preflight should be handled BEFORE routing to avoid 405
        if ($method === 'OPTIONS') {
            $preflight = new \Slim\Psr7\Response(204);
            foreach ($headersToSet as $name => $value) {
                if ($value !== null && $value !== '') {
                    $preflight = $preflight->withHeader($name, $value);
                }
            }
            // Debug header to verify middleware is active
            $preflight = $preflight->withHeader('X-CORS-Middleware', 'active');
            $preflight = $preflight->withAddedHeader('Vary', implode(', ', array_unique($varyValues)));

            // If client asked for a specific method, reflect it for clarity
            $reqMethod = $request->getHeaderLine('Access-Control-Request-Method');
            if ($reqMethod) {
                $preflight = $preflight->withHeader('Access-Control-Allow-Methods', $reqMethod);
                $preflight = $preflight->withAddedHeader('Vary', 'Access-Control-Request-Method');
            }

            return $preflight;
        }

        // For non-OPTIONS, proceed to downstream and then append headers
    $response = $handler->handle($request);
        foreach ($headersToSet as $name => $value) {
            if ($value !== null && $value !== '') {
                $response = $response->withHeader($name, $value);
            }
        }
    $response = $response->withAddedHeader('Vary', implode(', ', array_unique($varyValues)));
    // Debug header as well for non-OPTIONS
    $response = $response->withHeader('X-CORS-Middleware', 'active');

        return $response;
    }
}

