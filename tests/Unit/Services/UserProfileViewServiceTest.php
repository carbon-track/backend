<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\UserProfileViewService;
use PHPUnit\Framework\TestCase;

class UserProfileViewServiceTest extends TestCase
{
    public function testBuildProfileFieldsFallsBackToLegacyLocationAndSchool(): void
    {
        $regionService = $this->createMock(RegionService::class);
        $regionService->expects($this->once())
            ->method('getRegionContext')
            ->with('US-UM-81')
            ->willReturn([
                'region_code' => 'US-UM-81',
                'region_label' => null,
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => null,
            ]);

        $service = new UserProfileViewService($regionService);

        $fields = $service->buildProfileFields([
            'school' => 'Legacy Academy',
            'location' => 'US-UM-81',
        ]);

        $this->assertNull($fields['school_id']);
        $this->assertSame('Legacy Academy', $fields['school_name']);
        $this->assertSame('US-UM-81', $fields['region_code']);
        $this->assertSame('US', $fields['country_code']);
        $this->assertSame('UM-81', $fields['state_code']);
    }

    public function testBuildProfileFieldsFallsBackToLegacySchoolWhenJoinedNameMissing(): void
    {
        $regionService = $this->createMock(RegionService::class);
        $regionService->expects($this->once())
            ->method('getRegionContext')
            ->with('US-UM-81')
            ->willReturn([
                'region_code' => 'US-UM-81',
                'region_label' => 'US-UM-81',
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => null,
            ]);

        $service = new UserProfileViewService($regionService);

        $row = [
            'school_id' => 7,
            'school_name' => null,
            'school' => 'Legacy Academy',
            'region_code' => 'US-UM-81',
        ];
        $fields = $service->buildProfileFields($row);

        $legacy = $service->buildLegacyDisplayFields($row, $fields);

        $this->assertSame(7, $fields['school_id']);
        $this->assertSame('Legacy Academy', $fields['school_name']);
        $this->assertSame('Legacy Academy', $legacy['school']);
        $this->assertSame('US-UM-81', $legacy['location']);
    }

    public function testCanonicalFieldsTakePriorityOverLegacyValues(): void
    {
        $regionService = $this->createMock(RegionService::class);
        $regionService->expects($this->once())
            ->method('getRegionContext')
            ->with('US-UM-81')
            ->willReturn([
                'region_code' => 'US-UM-81',
                'region_label' => 'United States · Baker Island',
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => 'Baker Island',
            ]);

        $service = new UserProfileViewService($regionService);

        $row = [
            'school_id' => 7,
            'school_name' => 'Canonical Academy',
            'school' => 'Legacy Academy',
            'region_code' => 'US-UM-81',
            'location' => 'CN-GD',
        ];

        $fields = $service->buildProfileFields($row);
        $legacy = $service->buildLegacyDisplayFields($row, $fields);

        $this->assertSame(7, $fields['school_id']);
        $this->assertSame('Canonical Academy', $fields['school_name']);
        $this->assertSame('US-UM-81', $fields['region_code']);
        $this->assertSame('Canonical Academy', $legacy['school']);
        $this->assertSame('United States · Baker Island', $legacy['location']);
    }
}
