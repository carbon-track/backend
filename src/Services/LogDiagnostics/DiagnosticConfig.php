<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use CarbonTrack\Support\Environment;
use DateTimeImmutable;
use DateTimeZone;

final class DiagnosticConfig
{
    public const API = '/api/v1/log-diagnostics';
    public const ADMIN = '/api/v1/admin/log-diagnostics';
    public const RESOURCE_METADATA = '/.well-known/oauth-protected-resource' . self::API . '/mcp';
    public const ISSUER_METADATA = '/.well-known/oauth-authorization-server' . self::API . '/oauth';
    public const SCOPE = 'log_diagnostics.read';
    public const TYPES = ['system', 'error', 'audit', 'llm'];
    public const AUDIT_CATEGORY = 'log_diagnostics';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $apiOrigin,
        public readonly string $frontendOrigin,
        public readonly string $cursorKey,
        public readonly DateTimeZone $databaseLogTimezone,
        public readonly DateTimeZone $errorLogTimezone,
        public readonly int $requestsPerMinute = 10,
        public readonly int $maxConcurrent = 2,
        public readonly int $timeoutMs = 10000,
        public readonly int $maxSessionMinutes = 120,
        public readonly array $oauthClients = []
    ) {
    }

    public static function fromEnvironment(): self
    {
        $enabled = filter_var(Environment::get('LOG_DIAGNOSTICS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $api = rtrim(Environment::string('APP_URL'), '/');
        $frontend = rtrim(Environment::string('FRONTEND_URL'), '/');
        $key = Environment::string('LOG_DIAGNOSTICS_CURSOR_KEY');
        $clients = json_decode(Environment::string('LOG_DIAGNOSTICS_OAUTH_CLIENTS_JSON', '[]'), true);
        try {
            $dbZone = new DateTimeZone(Environment::string('LOG_DIAGNOSTICS_DB_LOG_TIMEZONE', 'UTC'));
            $errorZone = new DateTimeZone(
                Environment::string('LOG_DIAGNOSTICS_ERROR_LOG_TIMEZONE', date_default_timezone_get())
            );
        } catch (\Exception) {
            $enabled = false;
            $dbZone = $errorZone = new DateTimeZone('UTC');
        }

        if ($enabled && (!self::validOrigin($api) || !self::validOrigin($frontend)
            || strlen($key) < 32 || !is_array($clients))) {
            $enabled = false;
        }

        return new self(
            $enabled,
            $api,
            $frontend,
            $key,
            $dbZone,
            $errorZone,
            max(1, Environment::int('LOG_DIAGNOSTICS_REQUESTS_PER_MINUTE', 10)),
            max(1, Environment::int('LOG_DIAGNOSTICS_MAX_CONCURRENT', 2)),
            max(100, Environment::int('LOG_DIAGNOSTICS_TIMEOUT_MS', 10000)),
            max(5, Environment::int('LOG_DIAGNOSTICS_MAX_SESSION_MINUTES', 120)),
            is_array($clients) ? $clients : []
        );
    }

    private static function validOrigin(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') === 'https') {
            return true;
        }
        return ($parts['scheme'] ?? '') === 'http'
            && in_array(Environment::string('APP_ENV'), ['development', 'testing'], true)
            && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function resource(): string
    {
        return $this->apiOrigin . self::API . '/mcp';
    }

    public function issuer(): string
    {
        return $this->apiOrigin . self::API . '/oauth';
    }

    public function ensureEnabled(): void
    {
        if (!$this->enabled) {
            throw new DiagnosticProblem(404, 'diagnostics_disabled', 'Log diagnostics are disabled.');
        }
    }

    public static function timestamp(mixed $value, string $field): int
    {
        if (!is_string($value) || !preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
            $value
        )) {
            throw DiagnosticProblem::invalid($field . ' must be an RFC 3339 date and time with an offset.');
        }
        $parsed = date_parse($value);
        if ($parsed['error_count'] || $parsed['warning_count']) {
            throw DiagnosticProblem::invalid($field . ' is not a valid date and time.');
        }
        // The log tables store whole seconds. Round either half-open boundary up
        // to the next representable timestamp when a fraction is supplied.
        return (new DateTimeImmutable($value))->getTimestamp() + ($parsed['fraction'] > 0 ? 1 : 0);
    }

    public static function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    public static function secret(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
