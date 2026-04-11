<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

final class InputValueNormalizer
{
    private function __construct()
    {
    }

    public static function boolean(mixed $value, string $field, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            if (floor($value) !== $value) {
                throw new \InvalidArgumentException($field . ' must be a boolean');
            }

            return ((int) $value) !== 0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $default;
            }

            $booleanValue = filter_var($trimmed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($booleanValue !== null) {
                return $booleanValue;
            }

            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                return ((int) $trimmed) !== 0;
            }
        }

        throw new \InvalidArgumentException($field . ' must be a boolean');
    }

    public static function integer(mixed $value, string $field, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            if (floor($value) !== $value) {
                throw new \InvalidArgumentException($field . ' must be an integer');
            }

            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $default;
            }

            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
        }

        throw new \InvalidArgumentException($field . ' must be an integer');
    }

    public static function booleanFlagInteger(mixed $value, string $field, int $default = 0): int
    {
        return self::boolean($value, $field, $default !== 0) ? 1 : 0;
    }
}
