<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use mysqli;
use mysqli_result;
use Throwable;

final class LegacyMysqlDiagnosticReader
{
    private ?mysqli $reader = null;
    private ?string $controlMarker = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function select(string $sql, array $bindings, int $deadline): array
    {
        try {
            $reader = $this->reader ??= $this->connect($deadline);
            $values = [$sql];
            $variables = [];
            $types = 's';
            foreach (array_values($this->db->prepareBindings($bindings)) as $index => $value) {
                $variables[] = '@ct_diag_param_' . $index;
                $values[] = $value;
                $types .= match (true) {
                    is_int($value) => 'i',
                    is_float($value) => 'd',
                    default => 's',
                };
            }

            // Bind the generated SQL and its values before sending the asynchronous commands.
            $assignments = array_map(static fn (string $variable): string => $variable . ' = ?', $variables);
            $setupSql = implode(', ', array_merge(['@ct_diag_sql = ?'], $assignments));
            $statement = $reader->prepare('SET ' . $setupSql);
            if ($statement === false) {
                throw DiagnosticProblem::unavailable();
            }
            try {
                if (!$statement->bind_param($types, ...$values) || !$statement->execute()) {
                    throw DiagnosticProblem::unavailable();
                }
            } finally {
                $statement->close();
            }

            $this->executeUntil($reader, 'PREPARE ct_diagnostic FROM @ct_diag_sql', $deadline);
            $execute = $variables === []
                ? 'EXECUTE ct_diagnostic'
                : 'EXECUTE ct_diagnostic USING ' . implode(', ', $variables);
            $result = $this->executeUntil($reader, $execute, $deadline);
            if (!$result instanceof mysqli_result) {
                throw DiagnosticProblem::unavailable();
            }

            $rows = [];
            try {
                while (is_object($row = $result->fetch_object())) {
                    $rows[] = $row;
                }
                if ($row === false) {
                    throw DiagnosticProblem::unavailable();
                }
            } finally {
                $result->free();
            }
            $this->remainingMicroseconds($deadline);
            return $rows;
        } catch (Throwable $failure) {
            if (!$failure instanceof DiagnosticProblem && hrtime(true) >= $deadline) {
                throw DiagnosticProblem::queryTimeout();
            }
            throw $failure;
        }
    }

    public function close(): void
    {
        $reader = $this->reader;
        $this->reader = null;
        $this->controlMarker = null;
        $reader?->close();
    }

    private function connect(int $deadline): mysqli
    {
        $timeoutSeconds = (int) ceil($this->remainingMicroseconds($deadline) / 1000000);
        if (!function_exists('mysqli_poll') || !defined('MYSQLI_ASYNC')) {
            throw DiagnosticProblem::unavailable();
        }

        $config = $this->db->getConfig();
        $host = $config['host'] ?? 'localhost';
        $reader = mysqli_init();
        if (str_starts_with($host, 'p:') || $reader === false
            || !$reader->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeoutSeconds)
            || !$reader->options(MYSQLI_OPT_READ_TIMEOUT, $timeoutSeconds)
            || !$reader->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true)
            || !$reader->real_connect($host, $config['username'], $config['password'], $config['database'],
                (int) ($config['port'] ?? 3306), $config['unix_socket'] ?? null)) {
            throw DiagnosticProblem::unavailable();
        }

        // Verify the connection is cancellable by the same account on the same server.
        $identitySql = 'SELECT @@server_uuid AS server_uuid, CURRENT_USER() AS account';
        $marker = bin2hex(random_bytes(16));
        $session = $this->db->selectOne(
            $identitySql . ', @@character_set_connection AS charset, @@collation_connection AS collation_name, '
                . '@@time_zone AS time_zone, @@sql_mode AS sql_mode, (@ct_diag_cancel_guard := ?) AS cancel_guard',
            [$marker],
            false
        );
        $identity = $reader->query($identitySql);
        if (!$identity instanceof mysqli_result) {
            throw DiagnosticProblem::unavailable();
        }
        $actual = $identity->fetch_assoc();
        $identity->free();
        if ($session === null || !$actual
            || $session->cancel_guard !== $marker
            || $session->server_uuid !== $actual['server_uuid']
            || $session->account !== $actual['account']
            || !$reader->set_charset($session->charset)
            || !$reader->execute_query('SET SESSION collation_connection = ?, SESSION time_zone = ?, SESSION sql_mode = ?',
                [$session->collation_name, $session->time_zone, $session->sql_mode])) {
            throw DiagnosticProblem::unavailable();
        }
        $this->remainingMicroseconds($deadline);
        $this->controlMarker = $marker;
        return $reader;
    }

    private function executeUntil(mysqli $reader, string $sql, int $deadline): mysqli_result|true
    {
        $this->remainingMicroseconds($deadline);
        try {
            if ($reader->query($sql, MYSQLI_ASYNC) !== true) {
                throw DiagnosticProblem::unavailable();
            }
            $remaining = $this->remainingMicroseconds($deadline);
            $read = $errors = [$reader];
            $reject = [];
            $ready = mysqli_poll($read, $errors, $reject, intdiv($remaining, 1000000), $remaining % 1000000);
            if ($ready === 0) {
                throw DiagnosticProblem::queryTimeout();
            }
            if ($ready === false || $read === []) {
                throw DiagnosticProblem::unavailable();
            }
            $this->remainingMicroseconds($deadline);
            $result = $reader->reap_async_query();
            if ($result === false) {
                throw DiagnosticProblem::unavailable();
            }
            return $result;
        } catch (Throwable $failure) {
            // Cancel work on this dedicated connection before releasing the authorization lease.
            $this->cancel($reader);
            throw $failure;
        }
    }

    private function cancel(mysqli $reader): void
    {
        $threadId = (int) $reader->thread_id;
        if ($threadId < 1 || $this->controlMarker === null) {
            throw DiagnosticProblem::unavailable();
        }
        try {
            // The marker detects a replaced connection. The transaction prevents retrying KILL on a new connection.
            $this->db->transaction(function () use ($threadId): void {
                $session = $this->db->selectOne('SELECT @ct_diag_cancel_guard AS cancel_guard', [], false);
                if (($session->cancel_guard ?? null) !== $this->controlMarker
                    || !$this->db->unprepared('KILL CONNECTION ' . $threadId)) {
                    throw DiagnosticProblem::unavailable();
                }
            });
        } catch (QueryException $error) {
            // ER_NO_SUCH_THREAD means the dedicated connection has already stopped.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1094) {
                throw DiagnosticProblem::unavailable();
            }
        }
    }

    private function remainingMicroseconds(int $deadline): int
    {
        $remaining = intdiv($deadline - hrtime(true), 1000);
        if ($remaining < 1) {
            throw DiagnosticProblem::queryTimeout();
        }
        return $remaining;
    }
}
