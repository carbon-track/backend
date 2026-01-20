<?php

declare(strict_types=1);

namespace CarbonTrack\Support;

final class RequestIdNormalizer
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Normalize request_id values with optional null-on-empty handling.
     *
     * @param mixed $value
     */
    public static function normalize($value, bool $nullIfEmpty = true): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return $nullIfEmpty ? null : '';
        }

        if (preg_match(self::UUID_PATTERN, $trimmed) === 1) {
            return strtolower($trimmed);
        }

        return $trimmed;
    }
}
