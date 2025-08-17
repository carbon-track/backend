<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Performance;

use PHPUnit\Framework\TestCase;

class ApiPerformanceTest extends TestCase
{
    public function testCarbonCalculationPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            // Simulate carbon calculation
            $carbonFactor = 2.5;
            $amount = rand(1, 100);
            $carbonSaved = $carbonFactor * $amount;
            $points = $carbonSaved * 10;
            
            // Basic validation
            $this->assertGreaterThan(0, $carbonSaved);
            $this->assertGreaterThan(0, $points);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $iterations;

        // Assert that average calculation time is under 1ms
        $this->assertLessThan(0.001, $avgTime, 
            "Carbon calculation should complete in under 1ms on average. Actual: {$avgTime}s");

        echo "\nPerformance Results:\n";
        echo "Total iterations: {$iterations}\n";
        echo "Total time: " . round($totalTime * 1000, 2) . "ms\n";
        echo "Average time per calculation: " . round($avgTime * 1000, 4) . "ms\n";
    }

    public function testPasswordHashingPerformance(): void
    {
        $iterations = 10; // Fewer iterations as password hashing is intentionally slow
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $password = 'testpassword' . $i;
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->assertTrue(password_verify($password, $hash));
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $iterations;

        // Password hashing should be reasonably fast but secure
        $this->assertLessThan(1.0, $avgTime, 
            "Password hashing should complete in under 1 second on average. Actual: {$avgTime}s");

        echo "\nPassword Hashing Performance:\n";
        echo "Total iterations: {$iterations}\n";
        echo "Total time: " . round($totalTime * 1000, 2) . "ms\n";
        echo "Average time per hash: " . round($avgTime * 1000, 2) . "ms\n";
    }

    public function testJsonProcessingPerformance(): void
    {
        $iterations = 10000;
        $testData = [
            'user_id' => 123,
            'activity' => 'walking',
            'amount' => 5.5,
            'carbon_saved' => 13.75,
            'points' => 137,
            'timestamp' => '2025-01-01 12:00:00',
            'metadata' => [
                'location' => 'Beijing',
                'weather' => 'sunny',
                'temperature' => 25
            ]
        ];

        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $json = json_encode($testData);
            $decoded = json_decode($json, true);
            
            $this->assertIsString($json);
            $this->assertIsArray($decoded);
            $this->assertEquals($testData['user_id'], $decoded['user_id']);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        $avgTime = $totalTime / $iterations;

        // JSON processing should be very fast
        $this->assertLessThan(0.0001, $avgTime, 
            "JSON processing should complete in under 0.1ms on average. Actual: {$avgTime}s");

        echo "\nJSON Processing Performance:\n";
        echo "Total iterations: {$iterations}\n";
        echo "Total time: " . round($totalTime * 1000, 2) . "ms\n";
        echo "Average time per operation: " . round($avgTime * 1000, 4) . "ms\n";
    }

    public function testMemoryUsage(): void
    {
        $initialMemory = memory_get_usage();
        
        // Simulate processing multiple activities
        $activities = [];
        for ($i = 0; $i < 1000; $i++) {
            $activities[] = [
                'id' => $i,
                'type' => 'walking',
                'amount' => rand(1, 20),
                'carbon_saved' => rand(1, 50),
                'points' => rand(10, 500),
                'created_at' => date('Y-m-d H:i:s')
            ];
        }

        $peakMemory = memory_get_peak_usage();
        $memoryUsed = $peakMemory - $initialMemory;

        // Memory usage should be reasonable (under 10MB for 1000 activities)
        $this->assertLessThan(10 * 1024 * 1024, $memoryUsed, 
            "Memory usage should be under 10MB. Actual: " . round($memoryUsed / 1024 / 1024, 2) . "MB");

        echo "\nMemory Usage Test:\n";
        echo "Activities processed: 1000\n";
        echo "Memory used: " . round($memoryUsed / 1024 / 1024, 2) . "MB\n";
        echo "Peak memory: " . round($peakMemory / 1024 / 1024, 2) . "MB\n";
    }
}

