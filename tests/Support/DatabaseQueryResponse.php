<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Support;

/** Response and optional binding assertions for one SQL operation in a unit test. */
class DatabaseQueryResponse
{
    public string $shape = 'write';
    public bool $checkParameters = false;

    public function bindings(array $bindings): bool
    {
        return true;
    }

    public function parameter(int|string $key, mixed $value, string $type): bool
    {
        return true;
    }

    public function row(): mixed
    {
        return null;
    }

    public function rows(): array
    {
        return [];
    }

    public function value(): mixed
    {
        return null;
    }

    public function affected(): int
    {
        return 0;
    }
}
