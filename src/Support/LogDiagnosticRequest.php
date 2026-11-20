<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

use CarbonTrack\Services\LogDiagnostics\DiagnosticConfig;
use Psr\Http\Message\ServerRequestInterface;

final class LogDiagnosticRequest
{
    public ?int $adminId = null;
    public array $query = [];

    public static function context(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute(self::class);
        return $context instanceof self ? $context : null;
    }

    public static function path(string $path): ?string
    {
        if (preg_match('#/api/v1/(?:admin/)?log-diagnostics(?=/|$)#', $path, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $segments = explode('/', substr($path, $matches[0][1]));
            $static = [
                'api', 'v1', 'admin', 'log-diagnostics', 'summary', 'search', 'context', 'settings', 'mcp',
                'oauth', 'authorize', 'token', 'consent', 'sessions', 'credentials', 'revoke',
                'oauth-requests', 'decision', 'access-audits',
            ];
            return implode('/', array_map(
                static fn (string $segment): string => $segment === '' || in_array($segment, $static, true)
                    ? $segment : '{id}',
                $segments
            ));
        }
        foreach ([DiagnosticConfig::RESOURCE_METADATA, DiagnosticConfig::ISSUER_METADATA] as $known) {
            if (str_ends_with($path, $known)) {
                return $known;
            }
        }
        return null;
    }

    public static function requestId(ServerRequestInterface $request): ?string
    {
        $id = $request->getAttribute('request_id');
        return is_string($id) && Uuid::isValid($id) ? $id : null;
    }
}
