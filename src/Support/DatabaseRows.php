<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

final class DatabaseRows
{
    public static function one(object|array|null $row): array|false
    {
        return $row !== null ? (array) $row : false;
    }

    public static function all(array $rows): array
    {
        return array_map(static fn (object|array $row): array => (array) $row, $rows);
    }

    public static function scalar(object|array|null $row, int $column = 0): mixed
    {
        $values = array_values((array) $row);

        return array_key_exists($column, $values) ? $values[$column] : false;
    }

    public static function column(array $rows): array
    {
        return array_map(self::scalar(...), $rows);
    }
}
