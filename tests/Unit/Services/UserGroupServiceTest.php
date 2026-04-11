<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\QuotaConfigService;
use CarbonTrack\Services\UserGroupService;
use PHPUnit\Framework\TestCase;

class UserGroupServiceTest extends TestCase
{
    public function testPreparePayloadNormalizesDefaultFlagFromStringInputs(): void
    {
        $service = new UserGroupService(new QuotaConfigService());
        $method = new \ReflectionMethod($service, 'preparePayload');
        $method->setAccessible(true);

        $normalizedFalse = $method->invoke($service, [
            'name' => 'Standard',
            'code' => 'standard',
            'is_default' => '',
        ], null);
        $normalizedTrue = $method->invoke($service, [
            'name' => 'VIP',
            'code' => 'vip',
            'is_default' => '1',
        ], null);
        $normalizedIndeterminate = $method->invoke($service, [
            'name' => 'Draft',
            'code' => 'draft',
            'is_default' => 'indeterminate',
        ], null);

        $this->assertArrayHasKey('is_default', $normalizedFalse);
        $this->assertFalse($normalizedFalse['is_default']);
        $this->assertTrue($normalizedTrue['is_default']);
        $this->assertFalse($normalizedIndeterminate['is_default']);
    }

    public function testPreparePayloadRejectsInvalidDefaultFlag(): void
    {
        $service = new UserGroupService(new QuotaConfigService());
        $method = new \ReflectionMethod($service, 'preparePayload');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is_default must be a boolean');

        $method->invoke($service, [
            'name' => 'Broken',
            'code' => 'broken',
            'is_default' => 'maybe',
        ], null);
    }
}
