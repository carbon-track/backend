<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use CarbonTrack\Support\SensitiveDataRedactor;
use CarbonTrack\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;

final class DiagnosticProjection
{
    private const OPERATIONAL_WORDS =
        'a after all application as attempt at authentication authorization before body bytes by cache cancelled certificate '
        . 'closed completed configuration conflict connection could curl database deadline denied dns download dropped duplicate '
        . 'empty endpoint error exceeded execution expired failed failure fetch filesystem for from gateway got handshake header '
        . 'http https in incomplete incorrect internal invalid io is limit lock locked malformed match method milliseconds missing '
        . 'mismatch ms network no not of on open operation or out overloaded payload permission pool port processing protocol '
        . 'provider proxy query queued read received record redacted redirect refresh rejected remote request required reset refused '
        . 'resolved response retry rate scheduled seconds sending server service session socket ssl status storage terminated '
        . 'the timed timeout tls to token transport unavailable unexpected unreachable unsupported upstream url user validation '
        . 'waiting was with worker write';

    private ?array $routes = null;

    public function __construct(private readonly DiagnosticConfig $config)
    {
    }

    public function timestamp(string $type, int $time): string
    {
        return (new DateTimeImmutable('@' . $time))->setTimezone($this->timezone($type))->format('Y-m-d H:i:s');
    }

    public function iso(string $type, string $time): string
    {
        return DiagnosticConfig::iso((new DateTimeImmutable($time, $this->timezone($type)))->getTimestamp());
    }

    public static function requestId(mixed $value): ?string
    {
        return is_string($value) && Uuid::isValid($value) ? strtolower($value) : null;
    }

    public static function label(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('~^[A-Za-z][A-Za-z0-9.:_/\\\\ -]{0,119}$~D', $value) !== 1) {
            return null;
        }
        return preg_match('~://|^ctdiag_|^sk[-_]|[a-f0-9]{32}~i', $value) === 1 ? null : $value;
    }

    public function row(string $type, object $row): array
    {
        $fields = match ($type) {
            'system' => [
                'method' => in_array($row->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
                    ? $row->method : null,
                'route' => $this->route($row->path),
                'status_code' => $this->number($row->status_code),
                'duration_ms' => $this->decimal($row->duration_ms),
            ],
            'error' => [
                'error_type' => self::label($row->error_type) ?? 'unknown',
                'location' => $this->location($row->error_file),
                'line' => $this->number($row->error_line),
            ],
            'audit' => [
                'action' => self::label($row->action),
                'operation_category' => self::label($row->operation_category),
                'actor_type' => self::label($row->actor_type),
                'status' => self::label($row->status),
            ],
            'llm' => [
                'source' => self::label($row->source),
                'model' => self::label($row->model),
                'status' => self::label($row->status),
                'latency_ms' => $this->decimal($row->latency_ms),
                'prompt_tokens' => $this->number($row->prompt_tokens),
                'completion_tokens' => $this->number($row->completion_tokens),
                'total_tokens' => $this->number($row->total_tokens),
            ],
        };

        return [
            'id' => (int) $row->id,
            'type' => $type,
            'occurred_at' => $this->iso($type, $row->occurred_at),
            'request_id' => self::requestId($row->request_id),
            'summary' => $type === 'error' ? $this->message($row->error_message) : null,
        ] + $fields;
    }

    private function timezone(string $type): DateTimeZone
    {
        return $type === 'error' ? $this->config->errorLogTimezone : $this->config->databaseLogTimezone;
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 && is_finite((float) $value) ? (float) $value : null;
    }

    private function route(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }
        $path = explode('?', $path, 2)[0];
        $routes = $this->routes ??= $this->loadRoutes();
        if (isset($routes['static'][$path])) {
            return $path;
        }
        foreach ($routes['parameters'] as $pattern => $template) {
            if (preg_match($pattern, $path) === 1) {
                return $template;
            }
        }
        return null;
    }

    private function loadRoutes(): array
    {
        $definition = json_decode(file_get_contents(dirname(__DIR__, 3) . '/openapi.json') ?: '', true);
        $static = [];
        $parameters = [];
        foreach (array_keys($definition['paths'] ?? []) as $path) {
            if (!str_starts_with($path, '/')) {
                continue;
            }
            $template = str_starts_with($path, '/api/') ? $path : '/api/v1' . $path;
            if (!str_contains($template, '{')) {
                $static[$template] = true;
                continue;
            }
            $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
            $pattern = implode('', array_map(
                static fn (string $part): string => str_starts_with($part, '{') ? '[^/]+'
                    : preg_quote($part, '~'),
                $parts
            ));
            $parameters['~^' . $pattern . '$~D'] = $template;
        }
        return ['static' => $static, 'parameters' => $parameters];
    }

    private function location(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match('~(?:^|/)(src/(?:[A-Za-z0-9]+/)*[A-Za-z0-9_]+\.php)$~D', str_replace('\\', '/', $value), $matches) === 1
            ? $matches[1] : null;
    }

    private function message(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return SensitiveDataRedactor::REDACTED;
        }
        if (preg_match('/\bSQLSTATE\[([A-Z0-9]{5})\]/', $value, $matches) === 1) {
            return 'Database error (SQLSTATE ' . $matches[1] . ')';
        }

        // Retain operational errors in recognized formats. Opaque messages can contain
        // arbitrary business data and are represented by their class and source location.
        if (preg_match('/^(?:upstream|connection|request|service|network|transport|http|cache|operation|database|filesystem|worker|provider|curl|failed to|unable to)\b/i', $value) !== 1
            || preg_match('/-----BEGIN|(?:\bselect\b.+\bfrom\b)|(?:\binsert\s+into\b)|(?:\bupdate\b.+\bset\b)/isu', $value) !== 0) {
            return SensitiveDataRedactor::REDACTED;
        }
        $pattern = <<<'REGEX'
~
    \b(?:(?:proxy[-_ ]?)?authorization|cookie|session[_ -]?id|api[_ -]?key|
       (?:access|refresh|auth)[_ -]?token|token|secret|password|passwd|private[_ -]?key|
       username|account|email|phone|mobile|address|user[_ -]?id|name)["']?\s*[:=]\s*
       (?:"[^"]*"|'[^']*'|(?:bearer\s+)?[^\s,;}\]]+)
    |\bbearer\s+[A-Za-z0-9._\~+/-]+=*
    |\b(?:ctdiag_(?:mcp|rest)_|sk[-_]|gh[pousr]_|github_pat_)[A-Za-z0-9_-]+
    |\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b
    |\b[A-Fa-f0-9]{24,}\b
    |\b[A-Za-z0-9_-]{32,}\b
    |\b[a-z][a-z0-9+.-]*://[^\s"'<>]+
    |[A-Z0-9.!#$%&'*+/=?^_{}|\~-]+@[A-Z0-9.-]+\.[A-Z]{2,}
    |\b(?:\d{17}[\dX]|\d{15})\b
    |\b(?:\d{1,3}\.){3}\d{1,3}\b
    |(?<!\w)\+?\d[\d()\s.-]{7,}\d(?!\w)
    |(?<!\w)(?:[A-Z]:[\\/]|/(?:home|root|etc|var|opt|mnt|srv|usr)/)[^\s"'<>]+
~ixu
REGEX;
        $safe = preg_replace($pattern, SensitiveDataRedactor::REDACTED, $value);
        if ($safe === null) {
            return SensitiveDataRedactor::REDACTED;
        }
        // Free text from older logs may mix operational context with names or
        // arbitrary business values even when no sensitive field label is present.
        $terms = array_fill_keys(explode(' ', self::OPERATIONAL_WORDS), true);
        $safe = preg_replace_callback('/[\p{L}\p{M}][\p{L}\p{M}\p{N}_-]*/u', static fn (array $match): string =>
            isset($terms[strtolower($match[0])]) ? $match[0] : SensitiveDataRedactor::REDACTED, $safe);
        return $safe === null ? SensitiveDataRedactor::REDACTED : trim($safe);
    }
}
