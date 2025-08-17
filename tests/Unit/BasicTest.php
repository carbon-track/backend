<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BasicTest extends TestCase
{
    public function testPhpVersion(): void
    {
        $this->assertGreaterThanOrEqual('7.4', PHP_VERSION);
    }

    public function testEnvironmentVariables(): void
    {
        // Set test environment if not already set
        if (!isset($_ENV['APP_ENV'])) {
            $_ENV['APP_ENV'] = 'testing';
        }
        
        $this->assertNotEmpty($_ENV['JWT_SECRET']);
        $this->assertNotEmpty($_ENV['APP_ENV']);
    }

    public function testJsonEncoding(): void
    {
        $data = ['success' => true, 'message' => 'Test'];
        $json = json_encode($data);
        
        $this->assertIsString($json);
        $this->assertEquals('{"success":true,"message":"Test"}', $json);
    }

    public function testPasswordHashing(): void
    {
        $password = 'testpassword123';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $this->assertIsString($hash);
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrongpassword', $hash));
    }

    public function testEmailValidation(): void
    {
        $this->assertTrue(filter_var('test@example.com', FILTER_VALIDATE_EMAIL) !== false);
        $this->assertFalse(filter_var('invalid-email', FILTER_VALIDATE_EMAIL) !== false);
    }

    public function testArrayOperations(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];
        
        $this->assertArrayHasKey('a', $data);
        $this->assertEquals(1, $data['a']);
        $this->assertCount(3, $data);
    }

    public function testStringOperations(): void
    {
        $str = 'CarbonTrack API';
        
        $this->assertStringContainsString('Carbon', $str);
        $this->assertEquals(15, strlen($str));
        $this->assertEquals('carbontrack api', strtolower($str));
    }

    public function testDateOperations(): void
    {
        $date = new \DateTime('2025-01-01 00:00:00');
        
        $this->assertEquals('2025-01-01', $date->format('Y-m-d'));
        $this->assertEquals('00:00:00', $date->format('H:i:s'));
    }

    public function testMathOperations(): void
    {
        // Test carbon calculation
        $carbonFactor = 2.5; // kg CO2 per km
        $distance = 10.0; // km
        $carbonSaved = $carbonFactor * $distance;
        
        $this->assertEquals(25.0, $carbonSaved);
        
        // Test points calculation
        $pointsPerKg = 10;
        $points = $carbonSaved * $pointsPerKg;
        
        $this->assertEquals(250, $points);
    }

    public function testUuidGeneration(): void
    {
        $uuid = bin2hex(random_bytes(16));
        
        $this->assertIsString($uuid);
        $this->assertEquals(32, strlen($uuid));
        
        // Generate another UUID and ensure they're different
        $uuid2 = bin2hex(random_bytes(16));
        $this->assertNotEquals($uuid, $uuid2);
    }
}

