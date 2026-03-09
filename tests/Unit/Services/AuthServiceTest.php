<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private string $jwtSecret = 'test-jwt-secret-key';
    private AuditLogService $auditLogService;
    private ErrorLogService $errorLogService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock PDO for testing
        $mockPdo = $this->createMock(\PDO::class);
        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->errorLogService = $this->createMock(ErrorLogService::class);
        
        $this->authService = new AuthService($this->jwtSecret, 'HS256', 86400, $this->auditLogService, $this->errorLogService);
        $this->authService->setDatabase($mockPdo);
    }

    public function testGenerateJwtToken(): void
    {
        $user = [
            'id' => 1,
            'uuid' => 'test-uuid',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'is_admin' => false
        ];

        $token = $this->authService->generateJwtToken($user);
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Token should have 3 parts separated by dots
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testValidateJwtToken(): void
    {
        $user = [
            'id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'is_admin' => false
        ];

        $token = $this->authService->generateJwtToken($user);
        $decoded = $this->authService->validateJwtToken($token);
        
        $this->assertIsArray($decoded);
        $this->assertEquals($user['id'], $decoded['user']->id);
        $this->assertEquals($user['username'], $decoded['user']->username);
        $this->assertEquals($user['uuid'], $decoded['user']->uuid);
    }

    public function testValidateTokenNormalizesUuidIntoMiddlewarePayload(): void
    {
        $user = [
            'id' => 42,
            'uuid' => '550e8400-e29b-41d4-a716-446655440042',
            'username' => 'uuid-user',
            'email' => 'uuid@example.com',
            'is_admin' => false,
        ];

        $token = $this->authService->generateJwtToken($user);
        $payload = $this->authService->validateToken($token);

        $this->assertSame($user['id'], $payload['user_id']);
        $this->assertSame($user['uuid'], $payload['uuid']);
        $this->assertSame($user['email'], $payload['email']);
        $this->assertSame('user', $payload['role']);
        $this->assertSame($user['uuid'], $payload['user']['uuid']);
    }

    public function testValidateJwtTokenWithInvalidToken(): void
    {
        $result = $this->authService->validateJwtToken('invalid.token.here');
        $this->assertNull($result);
    }

    public function testValidateJwtTokenWithExpiredToken(): void
    {
        // Create service with very short expiration for testing
        $shortExpiryService = new AuthService($this->jwtSecret, 'HS256', -1); // Already expired
        
        $user = [
            'id' => 1,
            'uuid' => 'test-uuid',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'is_admin' => false
        ];

        $expiredToken = $shortExpiryService->generateJwtToken($user);
        $result = $this->authService->validateJwtToken($expiredToken);
        
        $this->assertNull($result);
    }

    public function testHashPassword(): void
    {
        $password = 'testpassword123';
        $hash = $this->authService->hashPassword($password);
        
        $this->assertIsString($hash);
        $this->assertNotEquals($password, $hash);
        $this->assertTrue(password_verify($password, $hash));
    }

    public function testVerifyPassword(): void
    {
        $password = 'testpassword123';
        $hash = $this->authService->hashPassword($password);
        
        $this->assertTrue($this->authService->verifyPassword($password, $hash));
        $this->assertFalse($this->authService->verifyPassword('wrongpassword', $hash));
    }

    public function testValidateEmail(): void
    {
        $this->assertTrue($this->authService->validateEmail('test@example.com'));
        $this->assertTrue($this->authService->validateEmail('user.name+tag@domain.co.uk'));
        
        $this->assertFalse($this->authService->validateEmail('invalid-email'));
        $this->assertFalse($this->authService->validateEmail('test@'));
        $this->assertFalse($this->authService->validateEmail('@example.com'));
    }

    public function testValidatePasswordStrength(): void
    {
        $result = $this->authService->validatePasswordStrength('StrongPass123');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        $result = $this->authService->validatePasswordStrength('weak');
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testIsAdmin(): void
    {
        $adminUser = ['id' => 1, 'username' => 'admin', 'is_admin' => true];
        $regularUser = ['id' => 2, 'username' => 'user', 'is_admin' => false];

        $this->assertTrue($this->authService->isAdminUser($adminUser));
        $this->assertFalse($this->authService->isAdminUser($regularUser));
    }

    public function testGenerateSecureToken(): void
    {
        $token1 = $this->authService->generateSecureToken();
        $token2 = $this->authService->generateSecureToken();
        
        $this->assertIsString($token1);
        $this->assertIsString($token2);
        $this->assertEquals(64, strlen($token1)); // 32 bytes = 64 hex chars
        $this->assertNotEquals($token1, $token2); // Should be unique
    }

    public function testIsAccountLockedLogsAuditWhenThresholdReached(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['locked-user', '127.0.0.1']);
        $stmt->method('fetchColumn')->willReturn(5);

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['action'] ?? null) === 'auth_account_locked'
                    && ($payload['operation_category'] ?? null) === 'authentication'
                    && ($payload['data']['failed_attempts'] ?? null) === 5;
            }))
            ->willReturn(true);

        $service = new AuthService($this->jwtSecret, 'HS256', 86400, $audit, $this->createMock(ErrorLogService::class));
        $service->setDatabase($pdo);

        $this->assertTrue($service->isAccountLocked('locked-user', '127.0.0.1'));
    }
}

