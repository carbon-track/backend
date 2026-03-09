<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services\Webauthn;

use CarbonTrack\Services\Webauthn\CborDecoder;
use PHPUnit\Framework\TestCase;

final class CborDecoderTest extends TestCase
{
    public function testDecodeWithOffsetAdvancesPastSingleItemAndLeavesTrailingBytes(): void
    {
        $item = $this->cborEncode($this->cborMap([
            'credentialPublicKey' => $this->cborMap([
                1 => 2,
                3 => -7,
            ]),
        ]));
        $payload = $item . "\x01\x02extensions";
        $offset = 0;

        $decoded = CborDecoder::decodeWithOffset($payload, $offset);

        $this->assertSame([
            'credentialPublicKey' => [
                1 => 2,
                3 => -7,
            ],
        ], $decoded);
        $this->assertSame(strlen($item), $offset);
        $this->assertSame("\x01\x02extensions", substr($payload, $offset));
    }

    /**
     * @param mixed $value
     */
    private function cborEncode($value): string
    {
        if (is_array($value) && array_key_exists('__map', $value)) {
            $encoded = '';
            foreach ($value['__map'] as $key => $item) {
                $encoded .= $this->cborEncode($key) . $this->cborEncode($item);
            }

            return $this->encodeCborHeader(5, count($value['__map'])) . $encoded;
        }

        if (is_string($value)) {
            return $this->encodeCborItem(3, $value);
        }

        if (is_int($value)) {
            if ($value >= 0) {
                return $this->encodeCborHeader(0, $value);
            }

            return $this->encodeCborHeader(1, (-1 - $value));
        }

        throw new \InvalidArgumentException('Unsupported CBOR test value.');
    }

    /**
     * @return array{__map:array<mixed,mixed>}
     */
    private function cborMap(array $value): array
    {
        return ['__map' => $value];
    }

    private function encodeCborItem(int $majorType, string $payload): string
    {
        return $this->encodeCborHeader($majorType, strlen($payload)) . $payload;
    }

    private function encodeCborHeader(int $majorType, int $value): string
    {
        if ($value < 24) {
            return chr(($majorType << 5) | $value);
        }

        if ($value < 256) {
            return chr(($majorType << 5) | 24) . chr($value);
        }

        if ($value < 65536) {
            return chr(($majorType << 5) | 25) . pack('n', $value);
        }

        return chr(($majorType << 5) | 26) . pack('N', $value);
    }
}
