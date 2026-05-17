<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

use Psr\Http\Message\ServerRequestInterface;

final class ClientIpResolver
{
    public static function fromRequest(ServerRequestInterface $request, ?string $default = '0.0.0.0'): ?string
    {
        $serverParams = array_replace($_SERVER ?? [], $request->getServerParams());
        $headerMap = [
            'HTTP_CF_CONNECTING_IP' => $request->getHeaderLine('CF-Connecting-IP'),
            'HTTP_X_FORWARDED_FOR' => $request->getHeaderLine('X-Forwarded-For'),
            'HTTP_X_REAL_IP' => $request->getHeaderLine('X-Real-IP'),
            'HTTP_CLIENT_IP' => $request->getHeaderLine('Client-IP'),
        ];

        foreach ($headerMap as $key => $value) {
            $value = trim($value);
            if ($value !== '') {
                $serverParams[$key] = $value;
            }
        }

        return self::fromServerParams($serverParams, $default);
    }

    public static function fromServerParams(array $serverParams, ?string $default = null): ?string
    {
        $serverParams = array_replace($_SERVER ?? [], $serverParams);
        $fallback = self::firstValidIp([
            $serverParams['REMOTE_ADDR'] ?? null,
        ]) ?? $default;

        if ($fallback === null || !self::isTrustedProxyAddress($fallback)) {
            return $fallback;
        }

        $cloudflareIp = self::firstValidIp([
            $serverParams['HTTP_CF_CONNECTING_IP'] ?? null,
            $serverParams['CF_CONNECTING_IP'] ?? null,
            // Preserve compatibility with existing log snapshots that stored this typo.
            $serverParams['HTTP_CF_CONNNECTING_IP'] ?? null,
        ]);
        if ($cloudflareIp !== null) {
            return $cloudflareIp;
        }

        $forwardedIp = self::resolveForwardedForClient((string)($serverParams['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedIp !== null) {
            return $forwardedIp;
        }

        return self::firstValidIp([
            $serverParams['HTTP_X_REAL_IP'] ?? null,
            $serverParams['HTTP_CLIENT_IP'] ?? null,
        ]) ?? $fallback;
    }

    private static function firstValidIp(array $candidates): ?string
    {
        foreach ($candidates as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $value = trim($raw);
            if ($value === '') {
                continue;
            }
            $first = trim(explode(',', $value)[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return null;
    }

    private static function resolveForwardedForClient(string $header): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $header)), static fn ($part) => $part !== ''));
        if ($parts === []) {
            return null;
        }

        $leftmostValid = null;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = $parts[$i];
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }
            $leftmostValid = $candidate;
            if (!self::isTrustedProxyAddress($candidate)) {
                return $candidate;
            }
        }

        return $leftmostValid;
    }

    private static function isTrustedProxyAddress(string $remoteAddr): bool
    {
        $trustedCidrs = trim((string)($_ENV['TRUSTED_PROXY_CIDRS'] ?? getenv('TRUSTED_PROXY_CIDRS') ?: ''));
        if ($trustedCidrs === '' || $remoteAddr === '0.0.0.0') {
            return false;
        }

        foreach (preg_split('/\s*,\s*/', $trustedCidrs) ?: [] as $cidr) {
            if ($cidr !== '' && self::ipMatchesCidr($remoteAddr, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefixLength = filter_var($prefix, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($prefixLength === false || $prefixLength > strlen($ipBytes) * 8) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
