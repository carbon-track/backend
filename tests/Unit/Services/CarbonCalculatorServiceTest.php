<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\CarbonCalculatorService;

class CarbonCalculatorServiceTest extends TestCase
{
    private CarbonCalculatorService $carbonCalculator;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create mock logger for testing
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $this->carbonCalculator = new CarbonCalculatorService($mockLogger);
    }

    public function testCalculateCarbonReduction(): void
    {
        // Test basic calculation
        $activity = [
            'carbon_factor' => 2.5, // kg CO2 per unit
            'unit' => 'km'
        ];
        $amount = 10.0; // 10 km

        $result = $this->carbonCalculator->calculateCarbonReduction($activity, $amount);
        
        $this->assertEquals(25.0, $result); // 2.5 * 10 = 25 kg CO2
    }

    public function testCalculateCarbonReductionWithZeroAmount(): void
    {
        $activity = [
            'carbon_factor' => 2.5,
            'unit' => 'km'
        ];
        $amount = 0.0;

        $result = $this->carbonCalculator->calculateCarbonReduction($activity, $amount);
        
        $this->assertEquals(0.0, $result);
    }

    public function testCalculateCarbonReductionWithNegativeAmount(): void
    {
        $activity = [
            'carbon_factor' => 2.5,
            'unit' => 'km'
        ];
        $amount = -5.0;

        $result = $this->carbonCalculator->calculateCarbonReduction($activity, $amount);
        
        $this->assertEquals(0.0, $result); // Should not allow negative values
    }

    public function testCalculatePoints(): void
    {
        $carbonAmount = 25.0; // kg CO2
        $pointsPerKg = 10; // 10 points per kg CO2

        $result = $this->carbonCalculator->calculatePoints($carbonAmount, $pointsPerKg);
        
        $this->assertEquals(250, $result); // 25 * 10 = 250 points
    }

    public function testCalculatePointsWithDefaultRate(): void
    {
        $carbonAmount = 10.0; // kg CO2

        $result = $this->carbonCalculator->calculatePoints($carbonAmount);
        
        $this->assertEquals(100, $result); // Default rate is 10 points per kg
    }

    public function testCalculatePointsWithZeroCarbonAmount(): void
    {
        $carbonAmount = 0.0;

        $result = $this->carbonCalculator->calculatePoints($carbonAmount);
        
        $this->assertEquals(0, $result);
    }

    public function testValidateActivityData(): void
    {
        // Valid activity data
        $validActivity = [
            'id' => 'uuid-123',
            'name_zh' => '步行',
            'name_en' => 'Walking',
            'carbon_factor' => 2.5,
            'unit' => 'km',
            'category' => 'transport'
        ];

        $this->assertTrue($this->carbonCalculator->validateActivityData($validActivity));

        // Invalid activity data - missing required fields
        $invalidActivity1 = [
            'id' => 'uuid-123',
            'name_zh' => '步行'
            // Missing other required fields
        ];

        $this->assertFalse($this->carbonCalculator->validateActivityData($invalidActivity1));

        // Invalid activity data - invalid carbon factor
        $invalidActivity2 = [
            'id' => 'uuid-123',
            'name_zh' => '步行',
            'name_en' => 'Walking',
            'carbon_factor' => -1.0, // Negative factor
            'unit' => 'km',
            'category' => 'transport'
        ];

        $this->assertFalse($this->carbonCalculator->validateActivityData($invalidActivity2));

        // Update payload: allow partial fields as long as recognised field present
        $updatePayload = ['is_active' => false];
        $this->assertTrue($this->carbonCalculator->validateActivityData($updatePayload, true));

        $invalidUpdate = ['name_en' => ''];
        $this->assertFalse($this->carbonCalculator->validateActivityData($invalidUpdate, true));

        $this->assertFalse($this->carbonCalculator->validateActivityData([], true));
    }

    public function testValidateAmount(): void
    {
        $this->assertTrue($this->carbonCalculator->validateAmount(10.5));
        $this->assertTrue($this->carbonCalculator->validateAmount(0.0));
        $this->assertTrue($this->carbonCalculator->validateAmount(1000.0));

        $this->assertFalse($this->carbonCalculator->validateAmount(-5.0));
        $this->assertFalse($this->carbonCalculator->validateAmount(-0.1));
    }

    public function testGetSupportedUnits(): void
    {
        $units = $this->carbonCalculator->getSupportedUnits();

        $this->assertIsArray($units);
        $this->assertContains('km', $units);
        $this->assertContains('kg', $units);
        $this->assertContains('hours', $units);
        $this->assertContains('times', $units);
        $this->assertContains('kWh', $units);
    }

    public function testGetCarbonFactorByCategory(): void
    {
        // Test getting carbon factors for transport category
        $transportFactors = $this->carbonCalculator->getCarbonFactorByCategory('transport');
        
        $this->assertIsArray($transportFactors);
        $this->assertArrayHasKey('car', $transportFactors);
        $this->assertArrayHasKey('bus', $transportFactors);
        $this->assertArrayHasKey('bicycle', $transportFactors);

        // Test invalid category
        $invalidFactors = $this->carbonCalculator->getCarbonFactorByCategory('invalid_category');
        $this->assertEmpty($invalidFactors);
    }

    public function testConvertUnits(): void
    {
        // Test km to m conversion
        $result = $this->carbonCalculator->convertUnits(5.0, 'km', 'm');
        $this->assertEquals(5000.0, $result);

        // Test kg to g conversion
        $result = $this->carbonCalculator->convertUnits(2.5, 'kg', 'g');
        $this->assertEquals(2500.0, $result);

        // Test same unit conversion
        $result = $this->carbonCalculator->convertUnits(10.0, 'km', 'km');
        $this->assertEquals(10.0, $result);

        // Test unsupported conversion
        $result = $this->carbonCalculator->convertUnits(10.0, 'km', 'invalid_unit');
        $this->assertEquals(10.0, $result); // Should return original value
    }

    public function testCalculateMonthlyStats(): void
    {
        $activities = [
            ['carbon_amount' => 10.0, 'points' => 100, 'created_at' => '2025-01-15'],
            ['carbon_amount' => 15.0, 'points' => 150, 'created_at' => '2025-01-20'],
            ['carbon_amount' => 5.0, 'points' => 50, 'created_at' => '2025-01-25'],
        ];

        $stats = $this->carbonCalculator->calculateMonthlyStats($activities);

        $this->assertIsArray($stats);
        $this->assertEquals(30.0, $stats['total_carbon_saved']);
        $this->assertEquals(300, $stats['total_points_earned']);
        $this->assertEquals(3, $stats['total_activities']);
        $this->assertEquals(10.0, $stats['average_carbon_per_activity']);
    }

    public function testCalculateMonthlyStatsWithEmptyData(): void
    {
        $activities = [];

        $stats = $this->carbonCalculator->calculateMonthlyStats($activities);

        $this->assertIsArray($stats);
        $this->assertEquals(0.0, $stats['total_carbon_saved']);
        $this->assertEquals(0, $stats['total_points_earned']);
        $this->assertEquals(0, $stats['total_activities']);
        $this->assertEquals(0.0, $stats['average_carbon_per_activity']);
    }

    public function testCalculateCarbonSavingsWithProvidedActivity(): void
    {
        $activity = [
            'id' => 'activity-123',
            'name_zh' => '骑行',
            'name_en' => 'Cycling',
            'category' => 'transport',
            'carbon_factor' => 1.5,
            'unit' => 'km',
        ];

        $result = $this->carbonCalculator->calculateCarbonSavings($activity['id'], 4.0, $activity);

        $this->assertEqualsWithDelta(6.0, $result['carbon_savings'], 0.0001);
        $this->assertSame(60, $result['points_earned']);
        $this->assertSame(1.5, $result['carbon_factor']);
        $this->assertSame('km', $result['unit']);
        $this->assertSame('activity-123', $result['activity_id']);
        $this->assertSame('骑行', $result['activity_name_zh']);
        $this->assertSame('Cycling', $result['activity_name_en']);
    }

    public function testCalculateCarbonSavingsRejectsNegativeInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->carbonCalculator->calculateCarbonSavings('activity-negative', -1, [
            'carbon_factor' => 2,
        ]);
    }
}

