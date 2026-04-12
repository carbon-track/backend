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

    public function testPreparePayloadMergesSupportRoutingIntoConfigAndClampsValues(): void
    {
        $service = new UserGroupService(new QuotaConfigService());
        $method = new \ReflectionMethod($service, 'preparePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'name' => 'Support',
            'code' => 'support',
            'support_routing' => [
                'first_response_minutes' => '0',
                'resolution_minutes' => '2880',
                'routing_weight' => '0.05',
                'min_agent_level' => '9',
                'overdue_boost' => '-2',
                'tier_label' => '  escalated  ',
            ],
        ], [
            'quotas' => ['daily' => 5],
        ]);

        $this->assertSame(['daily' => 5], $payload['config']['quotas']);
        $this->assertSame([
            'first_response_minutes' => 1,
            'resolution_minutes' => 2880,
            'routing_weight' => 0.1,
            'min_agent_level' => 5,
            'overdue_boost' => 0.0,
            'tier_label' => 'escalated',
        ], $payload['config']['support_routing']);
    }

    public function testPreparePayloadFallsBackToDefaultsForInvalidSupportRoutingTypes(): void
    {
        $service = new UserGroupService(new QuotaConfigService());
        $method = new \ReflectionMethod($service, 'preparePayload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'name' => 'Fallback',
            'code' => 'fallback',
            'support_routing' => [
                'first_response_minutes' => 'soon',
                'resolution_minutes' => [],
                'routing_weight' => 'heavy',
                'min_agent_level' => 2.5,
                'overdue_boost' => new \stdClass(),
                'tier_label' => '   ',
            ],
        ], null);

        $this->assertSame([
            'first_response_minutes' => 240,
            'resolution_minutes' => 1440,
            'routing_weight' => 1.0,
            'min_agent_level' => 1,
            'overdue_boost' => 1.0,
            'tier_label' => 'standard',
        ], $payload['config']['support_routing']);
    }
}
