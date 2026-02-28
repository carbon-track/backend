<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\RegionService;
use PHPUnit\Framework\TestCase;

class RegionServiceTest extends TestCase
{
    public function testUsesDatasetWhenAvailable(): void
    {
        $previousRegionDataPath = $_ENV['REGION_DATA_PATH'] ?? null;
        unset($_ENV['REGION_DATA_PATH']);

        try {
            $datasetPath = realpath(__DIR__ . '/../../../storage/data/states.json');
            $this->assertNotFalse($datasetPath);

            $service = new RegionService($datasetPath, null);

            $this->assertSame('CN', $service->normalizeCountryCode('cn'));
            $this->assertSame('GD', $service->normalizeStateCode('gd'));
            $this->assertTrue($service->isValidRegion('CN', 'GD'));
            $this->assertFalse($service->isValidRegion('CN', 'INVALID'));
        } finally {
            if ($previousRegionDataPath !== null) {
                $_ENV['REGION_DATA_PATH'] = $previousRegionDataPath;
            } else {
                unset($_ENV['REGION_DATA_PATH']);
            }
        }
    }

    public function testFallsBackToCodeFormatWhenDatasetMissing(): void
    {
        $previousRegionDataPath = $_ENV['REGION_DATA_PATH'] ?? null;
        unset($_ENV['REGION_DATA_PATH']);

        try {
            $service = new RegionService('__missing__/states.json', null);

            $this->assertSame('CN', $service->normalizeCountryCode('cn'));
            $this->assertSame('GD', $service->normalizeStateCode('gd'));
            $this->assertTrue($service->isValidRegion('CN', 'GD'));
            $this->assertFalse($service->isValidRegion('C', 'GD'));
            $this->assertFalse($service->isValidRegion('CN', ''));
            $this->assertFalse($service->isValidRegion('CN', '-'));
            $this->assertFalse($service->isValidRegion('CN', 'GD-'));
            $this->assertFalse($service->isValidRegion('CN', '-GD'));
        } finally {
            if ($previousRegionDataPath !== null) {
                $_ENV['REGION_DATA_PATH'] = $previousRegionDataPath;
            } else {
                unset($_ENV['REGION_DATA_PATH']);
            }
        }
    }
}
