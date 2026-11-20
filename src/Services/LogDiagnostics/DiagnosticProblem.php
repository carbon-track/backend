<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use Illuminate\Database\QueryException;
use Throwable;

final class DiagnosticProblem extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
        public readonly ?int $retryAfter = null,
        private readonly ?string $component = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
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

    public static function unavailable(?string $component = null, ?Throwable $previous = null): self
    {
        return new self(503, 'diagnostics_unavailable', 'Diagnostics are temporarily unavailable.',
            component: $component, previous: $previous);
    }

    public static function queryTimeout(): self
    {
        return new self(504, 'query_timeout', 'The diagnostic query timed out.');
    }

    public function logDetails(): array
    {
        $details = ['reason' => $this->reason];
        if ($this->component !== null) {
            $details['failure_component'] = $this->component;
        }
        $cause = $this->getPrevious();
        if ($cause === null) {
            return $details;
        }

        $details['exception_type'] = $cause::class;
        $details['error_file'] = basename($cause->getFile());
        $details['error_line'] = $cause->getLine();
        if ($cause instanceof QueryException) {
            $details['sqlstate'] = $cause->errorInfo[0] ?? null;
            $details['driver_code'] = $cause->errorInfo[1] ?? null;
        } elseif ($cause instanceof \mysqli_sql_exception) {
            $details['sqlstate'] = $cause->getSqlState();
            $details['driver_code'] = $cause->getCode();
        }
        return $details;
    }
}
