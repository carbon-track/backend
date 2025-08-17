<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\User;

class UserTest extends TestCase
{
    public function testUserModelCreation(): void
    {
        $userData = [
            'id' => 1,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'real_name' => 'Test User',
            'phone' => '1234567890',
            'role' => 'user',
            'status' => 'active',
            'points' => 100,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00'
        ];

        $user = new User($userData);

        $this->assertEquals(1, $user->getId());
        $this->assertEquals('testuser', $user->getUsername());
        $this->assertEquals('test@example.com', $user->getEmail());
        $this->assertEquals('Test User', $user->getRealName());
        $this->assertEquals('1234567890', $user->getPhone());
        $this->assertEquals('user', $user->getRole());
        $this->assertEquals('active', $user->getStatus());
        $this->assertEquals(100, $user->getPoints());
    }

    public function testUserModelToArray(): void
    {
        $userData = [
            'id' => 1,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'real_name' => 'Test User',
            'role' => 'user',
            'status' => 'active',
            'points' => 100
        ];

        $user = new User($userData);
        $array = $user->toArray();

        $this->assertIsArray($array);
        $this->assertEquals($userData['id'], $array['id']);
        $this->assertEquals($userData['username'], $array['username']);
        $this->assertEquals($userData['email'], $array['email']);
        $this->assertEquals($userData['real_name'], $array['real_name']);
        $this->assertEquals($userData['role'], $array['role']);
        $this->assertEquals($userData['status'], $array['status']);
        $this->assertEquals($userData['points'], $array['points']);
    }

    public function testUserModelToArrayExcludesPassword(): void
    {
        $userData = [
            'id' => 1,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'hashed_password',
            'role' => 'user'
        ];

        $user = new User($userData);
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
    }

    public function testUserModelIsAdmin(): void
    {
        $adminUser = new User(['role' => 'admin']);
        $regularUser = new User(['role' => 'user']);
        $moderatorUser = new User(['role' => 'moderator']);

        $this->assertTrue($adminUser->isAdmin());
        $this->assertFalse($regularUser->isAdmin());
        $this->assertFalse($moderatorUser->isAdmin());
    }

    public function testUserModelIsActive(): void
    {
        $activeUser = new User(['status' => 'active']);
        $inactiveUser = new User(['status' => 'inactive']);
        $suspendedUser = new User(['status' => 'suspended']);

        $this->assertTrue($activeUser->isActive());
        $this->assertFalse($inactiveUser->isActive());
        $this->assertFalse($suspendedUser->isActive());
    }

    public function testUserModelHasSufficientPoints(): void
    {
        $user = new User(['points' => 100]);

        $this->assertTrue($user->hasSufficientPoints(50));
        $this->assertTrue($user->hasSufficientPoints(100));
        $this->assertFalse($user->hasSufficientPoints(150));
    }

    public function testUserModelAddPoints(): void
    {
        $user = new User(['points' => 100]);

        $user->addPoints(50);
        $this->assertEquals(150, $user->getPoints());

        $user->addPoints(0);
        $this->assertEquals(150, $user->getPoints());
    }

    public function testUserModelSubtractPoints(): void
    {
        $user = new User(['points' => 100]);

        $result = $user->subtractPoints(30);
        $this->assertTrue($result);
        $this->assertEquals(70, $user->getPoints());

        $result = $user->subtractPoints(100);
        $this->assertFalse($result);
        $this->assertEquals(70, $user->getPoints()); // Should not change
    }

    public function testUserModelGetDisplayName(): void
    {
        $userWithRealName = new User([
            'username' => 'testuser',
            'real_name' => 'Test User'
        ]);

        $userWithoutRealName = new User([
            'username' => 'testuser',
            'real_name' => null
        ]);

        $this->assertEquals('Test User', $userWithRealName->getDisplayName());
        $this->assertEquals('testuser', $userWithoutRealName->getDisplayName());
    }

    public function testUserModelValidation(): void
    {
        // Valid user data
        $validData = [
            'username' => 'testuser',
            'email' => 'test@example.com',
            'role' => 'user',
            'status' => 'active'
        ];

        $user = new User($validData);
        $this->assertTrue($user->isValid());

        // Invalid user data - missing required fields
        $invalidData = [
            'username' => 'testuser'
            // Missing email, role, status
        ];

        $invalidUser = new User($invalidData);
        $this->assertFalse($invalidUser->isValid());
    }

    public function testUserModelGetValidationErrors(): void
    {
        $invalidData = [
            'username' => '', // Empty username
            'email' => 'invalid-email', // Invalid email format
            'role' => 'invalid_role', // Invalid role
            'status' => 'invalid_status' // Invalid status
        ];

        $user = new User($invalidData);
        $errors = $user->getValidationErrors();

        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);
        $this->assertContains('Username is required', $errors);
        $this->assertContains('Invalid email format', $errors);
        $this->assertContains('Invalid role', $errors);
        $this->assertContains('Invalid status', $errors);
    }
}

