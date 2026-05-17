<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

/**
 * Shared recursive redactor for log payloads (system_logs, audit_logs, error_logs).
 *
 * Centralised here so that adding/removing a sensitive field name is a single edit
 * rather than three drift-prone copies. The redaction rule is: case-insensitive
 * key match against {@see SENSITIVE_KEYS} → replace value with the placeholder
 * `[REDACTED]`. Arrays and objects are walked recursively; scalars and other
 * types are passed through unchanged.
 */
final class SensitiveDataRedactor
{
    public const REDACTED = '[REDACTED]';
    private const MAX_RECURSION_DEPTH = 20;

    /**
     * Canonical lower-case set of body / param key names that must never be
     * persisted to log tables. Includes legacy entries plus the audit findings
     * B-102/B-205 superset (verification, reset, MFA, cron / SLA admin keys).
     *
     * @var string[]
     */
    public const SENSITIVE_KEYS = [
        'password',
        'pass',
        'current_password',
        'new_password',
        'confirm_password',
        'token',
        'authorization',
        'auth',
        'secret',
        'api_key',
        'access_token',
        'refresh_token',
        'session_id',
        'credit_card',
        'code',
        'otp',
        'verification_code',
        'verification_token',
        'reset_token',
        'cf_turnstile_response',
        'pow_nonce',
        'pow_challenge',
        'mobile_client_token',
        'x-mobile-client-token',
        'x-cron-key',
        'x-sla-sweep-key',
    ];

    /**
     * Sensitive HTTP/SAPI server-array keys (always upper-case prefixed with HTTP_).
     *
     * @var string[]
     */
    public const SENSITIVE_SERVER_KEYS = [
        'PHP_AUTH_PW',
        'HTTP_AUTHORIZATION',
        'HTTP_COOKIE',
        'HTTP_X_TURNSTILE_TOKEN',
        'HTTP_X_MOBILE_CLIENT_TOKEN',
        'HTTP_X_CRON_KEY',
        'HTTP_X_SLA_SWEEP_KEY',
    ];

    /**
     * Server keys whose names start with any of these prefixes are also redacted.
     *
     * @var string[]
     */
    public const SENSITIVE_SERVER_KEY_PREFIXES = [
        'HTTP_X_DEBUG_',
    ];

    /**
     * Redact a freeform body payload. Accepts arrays, objects, scalars, or null.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value)
    {
        return self::redactValue($value, 0, new \SplObjectStorage());
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function redactValue($value, int $depth, \SplObjectStorage $seenObjects)
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $normalizedKey = self::normalizeKeyName((string) $k);
                $outputKey = is_string($k) && str_contains($k, "\0") ? $normalizedKey : $k;
                if (self::isSensitiveKey($normalizedKey)) {
                    $out[$outputKey] = self::REDACTED;
                    continue;
                }
                $out[$outputKey] = self::redactValue($v, $depth + 1, $seenObjects);
            }
            return $out;
        }

        if (is_object($value)) {
            if ($seenObjects->contains($value)) {
                return self::REDACTED;
            }
            $seenObjects->attach($value);
            return self::redactValue((array) $value, $depth + 1, $seenObjects);
        }

        return $value;
    }

    /**
     * Redact a server-array snapshot ($_SERVER / $request->getServerParams()).
     * Same recursion rules apply, but the key set is the upper-case variant
     * so callers don't have to lower-case keys before passing them in.
     *
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function redactServer(array $server): array
    {
        $out = [];
        foreach ($server as $key => $value) {
            if (self::isSensitiveServerKey((string) $key)) {
                $out[$key] = self::REDACTED;
                continue;
            }
            $out[$key] = (is_array($value) || is_object($value)) ? self::redact($value) : $value;
        }
        return $out;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $needle = strtolower(trim(self::normalizeKeyName($key)));
        if ($needle === '') {
            return false;
        }
        return in_array($needle, self::SENSITIVE_KEYS, true);
    }

    private static function normalizeKeyName(string $key): string
    {
        if (!str_contains($key, "\0")) {
            return $key;
        }

        $parts = explode("\0", $key);
        $last = end($parts);
        return is_string($last) ? $last : $key;
    }

    public static function isSensitiveServerKey(string $key): bool
    {
        $upper = strtoupper(trim($key));
        if ($upper === '') {
            return false;
        }
        if (in_array($upper, self::SENSITIVE_SERVER_KEYS, true)) {
            return true;
        }
        foreach (self::SENSITIVE_SERVER_KEY_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
