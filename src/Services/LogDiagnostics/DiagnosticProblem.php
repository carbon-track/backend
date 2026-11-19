<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

final class DiagnosticProblem extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'invalid_token', 'A valid diagnostic token is required.');
    }

    public static function scope(): self
    {
        return new self(403, 'outside_scope', 'The query is outside this diagnostic authorization.');
    }

    public static function unavailable(): self
    {
        return new self(503, 'diagnostics_unavailable', 'Diagnostics are temporarily unavailable.');
    }
}
