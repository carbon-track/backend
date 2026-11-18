<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;

/** Executes the Illuminate methods against SQL expectations instead of a database. */
class DatabaseConnectionStub extends Connection
{
    public function __construct()
    {
        parent::__construct(null, '', '', ['driver' => 'mysql']);
        $this->setPostProcessor(new class extends Processor {
            public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
            {
                $connection = $query->getConnection();
                $connection->insert($sql, $values);

                return $connection->generatedKey();
            }
        });
    }

    public function responseFor(string $sql): mixed
    {
        return null;
    }

    public function generatedKey(): mixed
    {
        return 0;
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $response = $this->response($query, $bindings);

        return match ($response?->shape) {
            'rows' => array_map(static fn (array|object $row): object => (object) $row, $response->rows()),
            'row' => ($row = $response->row()) ? [(object) $row] : [],
            'column' => array_map(static fn (mixed $value): object => (object) ['value' => $value], $response->rows()),
            'value' => [(object) ['value' => $response->value()]],
            default => [],
        };
    }

    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $response = $this->response($query, $bindings);
        while ($response && ($row = $response->row())) {
            yield (object) $row;
        }
    }

    public function statement($query, $bindings = [])
    {
        $response = $this->responseFor($query);
        $this->checkParameters($response, $bindings);

        return $response instanceof DatabaseQueryResponse && $response->bindings($bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        return $this->response($query, $bindings)?->affected() ?? 0;
    }

    public function beginTransaction()
    {
        $this->transactions++;
    }

    public function commit()
    {
        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack($toLevel = null)
    {
        $this->transactions = $toLevel ?? max(0, $this->transactions - 1);
    }

    private function response(string $sql, array $bindings): ?DatabaseQueryResponse
    {
        $response = $this->responseFor($sql);
        $this->checkParameters($response, $bindings);

        if ($response instanceof DatabaseQueryResponse) {
            $response->bindings($bindings);
            return $response;
        }

        return null;
    }

    private function checkParameters(mixed $response, array $bindings): void
    {
        if (!$response instanceof DatabaseQueryResponse || !$response->checkParameters) {
            return;
        }

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;
            $response->parameter($parameter, $value, is_int($value) ? 'int' : 'string');
        }
    }
}
