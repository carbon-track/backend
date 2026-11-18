<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

class DatabaseService
{
    private Capsule $capsule;

    public function __construct(Capsule $capsule)
    {
        $this->capsule = $capsule;
    }

    /**
     * Get the Eloquent Capsule instance
     */
    public function getCapsule(): Capsule
    {
        return $this->capsule;
    }

    /**
     * Get the database connection
     */
    public function getConnection(): Connection
    {
        return $this->capsule->getConnection();
    }


    /**
     * Insert using bound SQL and return the generated key from the same connection.
     */
    public static function insertGetId(Connection $connection, string $sql, array $bindings = []): int
    {
        return (int) $connection->getPostProcessor()->processInsertGetId($connection->query(), $sql, $bindings);
    }

    public static function exceptionMessage(\Throwable $error): string
    {
        if ($error instanceof QueryException) {
            return $error->getPrevious()?->getMessage() ?? 'Database query failed';
        }

        return $error->getMessage();
    }

    /**
     * Check if database connection is alive
     */
    public function isConnected(): bool
    {
        try {
            $this->getConnection()->selectOne('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Begin a database transaction
     */
    public function beginTransaction(): void
    {
        $this->capsule->getConnection()->beginTransaction();
    }

    /**
     * Commit a database transaction
     */
    public function commit(): void
    {
        $this->capsule->getConnection()->commit();
    }

    /**
     * Rollback a database transaction
     */
    public function rollback(): void
    {
        $this->capsule->getConnection()->rollback();
    }

    /**
     * Execute a raw SQL query
     */
    public function raw(string $sql, array $bindings = []): mixed
    {
        return $this->capsule->getConnection()->select($sql, $bindings);
    }

    /**
     * Get table prefix
     */
    public function getTablePrefix(): string
    {
        return $this->capsule->getConnection()->getTablePrefix();
    }

    /**
     * Get database name
     */
    public function getDatabaseName(): string
    {
        return $this->capsule->getConnection()->getDatabaseName();
    }
}

