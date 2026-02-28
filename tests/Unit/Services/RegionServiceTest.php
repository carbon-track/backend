<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\RegionService;
use PHPUnit\Framework\TestCase;

class RegionServiceTest extends TestCase
{
    public function testUsesDatasetWhenAvailable(): void
    {
        $datasetPath = realpath(__DIR__ . '/../../../../frontend/public/locales/states.json');
        $this->assertNotFalse($datasetPath);

        $service = new RegionService($datasetPath ?: null, null);

        $this->assertSame('CN', $service->normalizeCountryCode('cn'));
        $this->assertSame('GD', $service->normalizeStateCode('gd'));
        $this->assertTrue($service->isValidRegion('CN', 'GD'));
        $this->assertFalse($service->isValidRegion('CN', 'INVALID'));
    }

    public function testFallsBackToCodeFormatWhenDatasetMissing(): void
    {
        $service = new RegionService('__missing__/states.json', null);

        $this->assertSame('CN', $service->normalizeCountryCode('cn'));
        $this->assertSame('GD', $service->normalizeStateCode('gd'));
        $this->assertTrue($service->isValidRegion('CN', 'GD'));
        $this->assertFalse($service->isValidRegion('C', 'GD'));
        $this->assertFalse($service->isValidRegion('CN', ''));
    }
}
