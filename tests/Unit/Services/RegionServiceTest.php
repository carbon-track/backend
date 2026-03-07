<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
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

    public function testMissingDatasetWritesAuditAndErrorLog(): void
    {
        $previousRegionDataPath = $_ENV['REGION_DATA_PATH'] ?? null;
        unset($_ENV['REGION_DATA_PATH']);
        $missingPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_missing_region_' . uniqid('', true) . '.json';

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['action'] ?? null) === 'region_dataset_missing'
                    && ($payload['operation_category'] ?? null) === 'system';
            }))
            ->willReturn(true);

        $error = $this->createMock(ErrorLogService::class);
        $error->expects($this->once())
            ->method('logError')
            ->with(
                'RegionDatasetError',
                $this->stringContains('Region dataset not found'),
                $this->anything(),
                $this->arrayHasKey('path')
            )
            ->willReturn(1);

        try {
            new RegionService($missingPath, null, $audit, $error);
        } finally {
            if ($previousRegionDataPath !== null) {
                $_ENV['REGION_DATA_PATH'] = $previousRegionDataPath;
            } else {
                unset($_ENV['REGION_DATA_PATH']);
            }
        }
    }
}
