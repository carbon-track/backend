<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

final class Environment
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (int) $value : $default;
    }
}
