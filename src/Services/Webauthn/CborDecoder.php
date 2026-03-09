<?php

declare(strict_types=1);

namespace CarbonTrack\Services\Webauthn;

final class CborDecoder
{
    public static function decode(string $payload)
    {
        $offset = 0;
        $value = self::decodeWithOffset($payload, $offset);
        if ($offset !== strlen($payload)) {
            throw new \InvalidArgumentException('Unexpected trailing bytes in CBOR payload.');
        }

        return $value;
    }

    public static function decodeWithOffset(string $payload, int &$offset)
    {
        return self::readItem($payload, $offset);
    }

    private static function readItem(string $payload, int &$offset)
    {
        if (!isset($payload[$offset])) {
            throw new \InvalidArgumentException('Unexpected end of CBOR payload.');
        }

        $initial = ord($payload[$offset++]);
        $majorType = $initial >> 5;
        $additional = $initial & 0x1f;

        switch ($majorType) {
            case 0:
                return self::readLength($payload, $offset, $additional);
            case 1:
                return -1 - self::readLength($payload, $offset, $additional);
            case 2:
                $length = self::readLength($payload, $offset, $additional);
                return self::readBytes($payload, $offset, $length);
            case 3:
                $length = self::readLength($payload, $offset, $additional);
                return self::readBytes($payload, $offset, $length);
            case 4:
                $length = self::readLength($payload, $offset, $additional);
                $items = [];
                for ($index = 0; $index < $length; $index++) {
                    $items[] = self::readItem($payload, $offset);
                }
                return $items;
            case 5:
                $length = self::readLength($payload, $offset, $additional);
                $items = [];
                for ($index = 0; $index < $length; $index++) {
                    $items[self::readItem($payload, $offset)] = self::readItem($payload, $offset);
                }
                return $items;
            case 7:
                return self::readSimpleValue($payload, $offset, $additional);
            default:
                throw new \InvalidArgumentException('Unsupported CBOR major type.');
        }
    }

    private static function readLength(string $payload, int &$offset, int $additional): int
    {
        if ($additional < 24) {
            return $additional;
        }

        if ($additional === 24) {
            return ord(self::readBytes($payload, $offset, 1));
        }

        if ($additional === 25) {
            return unpack('n', self::readBytes($payload, $offset, 2))[1];
        }

        if ($additional === 26) {
            return unpack('N', self::readBytes($payload, $offset, 4))[1];
        }

        if ($additional === 27) {
            $parts = unpack('N2', self::readBytes($payload, $offset, 8));
            return ((int) $parts[1] << 32) | (int) $parts[2];
        }

        throw new \InvalidArgumentException('Unsupported CBOR additional information value.');
    }

    private static function readSimpleValue(string $payload, int &$offset, int $additional)
    {
        if ($additional === 20) {
            return false;
        }

        if ($additional === 21) {
            return true;
        }

        if ($additional === 22) {
            return null;
        }

        throw new \InvalidArgumentException('Unsupported CBOR simple value.');
    }

    private static function readBytes(string $payload, int &$offset, int $length): string
    {
        if ($length < 0 || ($offset + $length) > strlen($payload)) {
            throw new \InvalidArgumentException('Unexpected end of CBOR payload.');
        }

        $value = substr($payload, $offset, $length);
        $offset += $length;

        return $value;
    }
}
