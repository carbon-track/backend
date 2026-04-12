<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use PDO;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;
    private string $jwtSecret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
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

    public function testGenerateTokenMarksSupportUsers(): void
    {
        $user = [
            'id' => 7,
            'uuid' => '550e8400-e29b-41d4-a716-446655440007',
            'username' => 'support-user',
            'email' => 'support@example.com',
            'role' => 'support',
            'is_admin' => false,
        ];

        $token = $this->authService->generateToken($user);
        $payload = $this->authService->validateToken($token);

        $this->assertSame('support', $payload['role']);
        $this->assertTrue($payload['user']['is_support']);
        $this->assertFalse($payload['user']['is_admin']);
    }

    public function testNormalizeUserRoleViewNormalizesFlagsConsistently(): void
    {
        $normalized = $this->authService->normalizeUserRoleView([
            'role' => 'support',
            'is_admin' => 0,
        ]);

        $this->assertSame('support', $normalized['role']);
        $this->assertFalse($normalized['is_admin']);
        $this->assertTrue($normalized['is_support']);
    }

    public function testGenerateJwtTokenUsesUuidAsSubjectWhenAvailable(): void
    {
        $user = [
            'id' => 9,
            'uuid' => '550e8400-e29b-41d4-a716-446655440009',
            'username' => 'subject-user',
            'email' => 'subject@example.com',
            'is_admin' => false,
        ];

        $token = $this->authService->generateJwtToken($user);
        $decoded = $this->authService->validateJwtToken($token);

        $this->assertIsArray($decoded);
        $this->assertSame($user['uuid'], $decoded['sub']);
    }

    public function testValidateTokenResolvesLocalUserIdFromUuidSubject(): void
    {
        $pdo = $this->makeSqliteUsersPdo();
        $pdo->exec(
            "INSERT INTO users (uuid, username, email, password, status, points, is_admin, created_at, updated_at)
             VALUES ('550e8400-e29b-41d4-a716-4466554400aa', 'uuid-user', 'uuid-user@example.com', 'hash', 'active', 0, 0, '2026-03-11 00:00:00', '2026-03-11 00:00:00')"
        );

        $service = new AuthService($this->jwtSecret, 'HS256', 86400, $this->auditLogService, $this->errorLogService);
        $service->setDatabase($pdo);

        $token = JWT::encode([
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => '550e8400-e29b-41d4-a716-4466554400aa',
            'user' => [
                'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
                'username' => 'uuid-user',
                'email' => 'uuid-user@example.com',
                'is_admin' => false,
            ],
        ], $this->jwtSecret, 'HS256');

        $payload = $service->validateToken($token);

        $this->assertSame('550e8400-e29b-41d4-a716-4466554400aa', $payload['uuid']);
        $this->assertSame(1, $payload['user_id']);
        $this->assertSame(1, $payload['user']['id']);
    }

    public function testValidateTokenProvisionLocalUserWhenUuidOnlyIdentityArrives(): void
    {
        $pdo = $this->makeSqliteUsersPdo();
        $service = new AuthService($this->jwtSecret, 'HS256', 86400, $this->auditLogService, $this->errorLogService);
        $service->setDatabase($pdo);

        $token = JWT::encode([
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => '550e8400-e29b-41d4-a716-4466554400ab',
            'user' => [
                'uuid' => '550e8400-e29b-41d4-a716-4466554400ab',
                'username' => 'new-sso-user',
                'email' => 'new-sso-user@example.com',
                'is_admin' => false,
            ],
        ], $this->jwtSecret, 'HS256');

        $payload = $service->validateToken($token);

        $this->assertSame('550e8400-e29b-41d4-a716-4466554400ab', $payload['uuid']);
        $this->assertIsInt($payload['user_id']);
        $this->assertGreaterThan(0, $payload['user_id']);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame('new-sso-user', $pdo->query('SELECT username FROM users LIMIT 1')->fetchColumn());
    }

    public function testValidateTokenProvisionLocalUserNormalizesUnknownRole(): void
    {
        $pdo = $this->makeSqliteUsersPdo();
        $service = new AuthService($this->jwtSecret, 'HS256', 86400, $this->auditLogService, $this->errorLogService);
        $service->setDatabase($pdo);

        $token = JWT::encode([
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => '550e8400-e29b-41d4-a716-4466554400ac',
            'user' => [
                'uuid' => '550e8400-e29b-41d4-a716-4466554400ac',
                'username' => 'unknown-role-user',
                'email' => 'unknown-role-user@example.com',
                'role' => 'moderator',
                'is_admin' => false,
            ],
        ], $this->jwtSecret, 'HS256');

        $payload = $service->validateToken($token);

        $this->assertSame('user', $payload['role']);
        $this->assertSame('user', $pdo->query('SELECT role FROM users LIMIT 1')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT is_admin FROM users LIMIT 1')->fetchColumn());
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

    public function testIsSupportUserAcceptsSupportAndAdmin(): void
    {
        $supportUser = ['id' => 2, 'username' => 'support', 'role' => 'support', 'is_admin' => false];
        $adminUser = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'is_admin' => true];
        $regularUser = ['id' => 3, 'username' => 'user', 'role' => 'user', 'is_admin' => false];

        $this->assertTrue($this->authService->isSupportUser($supportUser));
        $this->assertTrue($this->authService->isSupportUser($adminUser));
        $this->assertFalse($this->authService->isSupportUser($regularUser));
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

    private function makeSqliteUsersPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                username TEXT UNIQUE,
                email TEXT UNIQUE,
                password TEXT,
                role TEXT DEFAULT \'user\',
                status TEXT,
                points INTEGER DEFAULT 0,
                is_admin INTEGER DEFAULT 0,
                deleted_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        return $pdo;
    }
}

