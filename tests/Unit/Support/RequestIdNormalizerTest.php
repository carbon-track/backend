<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\RequestIdNormalizer;
use PHPUnit\Framework\TestCase;

class RequestIdNormalizerTest extends TestCase
{
    public function testNormalizeReturnsNullForNull(): void
    {
        $this->assertNull(RequestIdNormalizer::normalize(null));
    }

    public function testNormalizeReturnsNullForEmptyString(): void
    {
        $this->assertNull(RequestIdNormalizer::normalize(''));
    }

    public function testNormalizeReturnsNullForWhitespace(): void
    {
        $this->assertNull(RequestIdNormalizer::normalize(" \t\n"));
    }

    public function testNormalizeLowercasesUuid(): void
    {
        $value = '550E8400-E29B-41D4-A716-446655440001';
        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', RequestIdNormalizer::normalize($value));
    }

    public function testNormalizePreservesNonUuid(): void
    {
        $value = 'Req-ABC-123';
        $this->assertSame($value, RequestIdNormalizer::normalize($value));
    }

    public function testNormalizePreservesInvalidUuidVersion(): void
    {
        $value = '550E8400-E29B-61D4-A716-446655440001';
        $this->assertSame($value, RequestIdNormalizer::normalize($value));
    }

    public function testNormalizeAllowsEmptyStringWhenConfigured(): void
    {
        $this->assertSame('', RequestIdNormalizer::normalize('', false));
        $this->assertSame('', RequestIdNormalizer::normalize(" \t", false));
    }
}
