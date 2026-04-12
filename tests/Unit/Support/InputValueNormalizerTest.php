<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\InputValueNormalizer;
use PHPUnit\Framework\TestCase;

class InputValueNormalizerTest extends TestCase
{
    public function testBooleanAcceptsCanonicalValues(): void
    {
        $this->assertTrue(InputValueNormalizer::boolean(true, 'flag'));
        $this->assertFalse(InputValueNormalizer::boolean(false, 'flag'));
        $this->assertTrue(InputValueNormalizer::boolean(1, 'flag'));
        $this->assertFalse(InputValueNormalizer::boolean(0, 'flag'));
        $this->assertTrue(InputValueNormalizer::boolean('1', 'flag'));
        $this->assertFalse(InputValueNormalizer::boolean('0', 'flag'));
        $this->assertTrue(InputValueNormalizer::boolean('yes', 'flag'));
        $this->assertFalse(InputValueNormalizer::boolean('no', 'flag'));
    }

    public function testBooleanRejectsNonBooleanNumericValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('flag must be a boolean');

        InputValueNormalizer::boolean('2', 'flag');
    }

    public function testBooleanRejectsNegativeIntegerValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('flag must be a boolean');

        InputValueNormalizer::boolean(-1, 'flag');
    }
}
