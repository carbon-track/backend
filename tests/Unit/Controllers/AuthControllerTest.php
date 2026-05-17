<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\ProofOfWorkService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\CheckinService;
use CarbonTrack\Services\UserProfileViewService;
use PHPUnit\Framework\TestCase;

class AuthControllerTest extends TestCase
{
    public function testAuthControllerCanBeInstantiated(): void
    {
        // Create mocks
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $authController = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $this->assertInstanceOf(AuthController::class, $authController);
    }

    public function testAuthControllerHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(AuthController::class, 'register'));
        $this->assertTrue(method_exists(AuthController::class, 'login'));
        $this->assertTrue(method_exists(AuthController::class, 'refresh'));
        $this->assertTrue(method_exists(AuthController::class, 'logout'));
        $this->assertTrue(method_exists(AuthController::class, 'sendVerificationCode'));
        $this->assertTrue(method_exists(AuthController::class, 'verifyEmail'));
        $this->assertTrue(method_exists(AuthController::class, 'me'));
        $this->assertTrue(method_exists(AuthController::class, 'forgotPassword'));
        $this->assertTrue(method_exists(AuthController::class, 'resetPassword'));
        $this->assertTrue(method_exists(AuthController::class, 'changePassword'));
    }

    public function testAuthControllerMethodsArePublic(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        
        $registerMethod = $reflection->getMethod('register');
        $this->assertTrue($registerMethod->isPublic());
        
        $loginMethod = $reflection->getMethod('login');
        $this->assertTrue($loginMethod->isPublic());
        
        $logoutMethod = $reflection->getMethod('logout');
        $this->assertTrue($logoutMethod->isPublic());

        $refreshMethod = $reflection->getMethod('refresh');
        $this->assertTrue($refreshMethod->isPublic());
        
        $meMethod = $reflection->getMethod('me');
        $this->assertTrue($meMethod->isPublic());
    }

    public function testMeUsesCompatibleSchoolAndRegionFields(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockRegion = $this->createMock(RegionService::class);
        $mockRegion->method('getRegionContext')
            ->with('US-UM-81')
            ->willReturn([
                'region_code' => 'US-UM-81',
                'region_label' => 'US-UM-81',
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => null,
            ]);

        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'id' => 5,
            'uuid' => 'u-5',
            'username' => 'legacy-user',
            'email' => 'legacy@example.com',
            'school_id' => null,
            'school_name' => null,
            'school' => 'Legacy Academy',
            'region_code' => null,
            'location' => 'US-UM-81',
            'points' => 9,
            'is_admin' => 0,
            'avatar_id' => null,
            'avatar_path' => null,
            'created_at' => '2025-01-01 00:00:00',
        ]);

        $unreadStmt = $this->createMock(\PDOStatement::class);
        $unreadStmt->method('execute')->willReturn(true);
        $unreadStmt->method('fetchColumn')->willReturn(3);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $unreadStmt);

        $mockAuthService->method('getCurrentUser')->willReturn(['id' => 5]);

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            null,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion,
            null,
            new UserProfileViewService($mockRegion)
        );

        $request = makeRequest('GET', '/auth/me');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->me($request, $response);

        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('Legacy Academy', $json['data']['school_name']);
        $this->assertSame('US-UM-81', $json['data']['region_code']);
        $this->assertSame(3, $json['data']['unread_messages']);
    }

    public function testAuthControllerHasCorrectDependencies(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        $this->assertCount(13, $parameters);

        $expectedTypes = [
            'CarbonTrack\Services\AuthService',
            'CarbonTrack\Services\EmailService',
            'CarbonTrack\Services\TurnstileService',
            'CarbonTrack\Services\AuditLogService',
            'CarbonTrack\Services\MessageService',
            'CarbonTrack\Services\CloudflareR2Service',
            'Monolog\Logger',
            'PDO',
            'CarbonTrack\Services\ErrorLogService',
            'CarbonTrack\Services\RegionService',
            'CarbonTrack\Services\CheckinService',
            'CarbonTrack\Services\UserProfileViewService',
            'CarbonTrack\Services\ProofOfWorkService'
        ];
        $nullableIndexes = [5, 8, 10, 11, 12];

        foreach ($parameters as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType) {
                $this->assertEquals($expectedTypes[$index], $type->getName());
                if (in_array($index, $nullableIndexes, true)) {
                    $this->assertTrue($type->allowsNull());
                } else {
                    $this->assertFalse($type->allowsNull());
                }
            }
        }

    }

    public function testLoginCallsAuthAndWritesAudit(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockRegion = $this->createMock(RegionService::class);

        // mock PDO for selecting user and updating last login
        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'id' => 1,
            'uuid' => 'u-1',
            'username' => 'john',
            'email' => 'john@example.com',
            'school_id' => 2,
            'school_name' => 'Test School',
            'points' => 0,
            'is_admin' => 0,
            'avatar_url' => null,
            'lastlgn' => null,
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'email_verified_at' => null,
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_send_count' => 0,
            'verification_last_sent_at' => null
        ]);
        $updateStmt = $this->createMock(\PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $verificationStmt = $this->createMock(\PDOStatement::class);
        $verificationStmt->method('execute')->willReturn(true);
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt, $verificationStmt);

        $mockTurnstileService->method('verify')->with('test-turnstile')->willReturn(['success' => true]);
        $mockAuthService->method('generateToken')->willReturn('fake.jwt.token');
        $mockAuditLogService->expects($this->atLeastOnce())->method('log');
        $mockAuditLogService->expects($this->any())->method('logAuthOperation');
        $mockEmailService->expects($this->once())->method('sendVerificationCode')->willReturn(true);

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/login', [
            'username' => 'john',
            'password' => 'secret',
            'cf_turnstile_response' => 'test-turnstile',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('fake.jwt.token', $json['data']['token']);
        $this->assertEquals('john', $json['data']['user']['username']);
        $this->assertTrue($json['data']['email_verification_required']);
        $this->assertTrue($json['data']['email_verification_sent']);
        $this->assertNotEmpty($json['data']['verification_expires_at']);
    }

    public function testLoginRejectsNonStringCredentialsBeforeLockoutChecks(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockAuthService->expects($this->never())->method('isAccountLocked');
        $mockTurnstileService->expects($this->never())->method('verify');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $cases = [
            ['identifier' => ['john@example.com'], 'password' => 'secret123'],
            ['identifier' => 'john@example.com', 'password' => ['secret123']],
        ];

        foreach ($cases as $payload) {
            $request = makeRequest('POST', '/auth/login', $payload);
            $response = new \Slim\Psr7\Response();

            $resp = $controller->login($request, $response);
            $this->assertSame(400, $resp->getStatusCode());
            $json = json_decode((string) $resp->getBody(), true);
            $this->assertFalse($json['success']);
            $this->assertSame('MISSING_CREDENTIALS', $json['code']);
        }
    }

    public function testLoginDoesNotResendWhenVerificationStillValid(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockRegion = $this->createMock(RegionService::class);

        $now = new \DateTimeImmutable('now');
        $futureExpiry = $now->modify('+30 minutes')->format('Y-m-d H:i:s');
        $lastSentAt = $now->modify('-30 minutes')->format('Y-m-d H:i:s');
        $resendAvailableAt = (new \DateTimeImmutable($lastSentAt))->modify('+1 hour')->format('Y-m-d H:i:s');

        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'id' => 2,
            'uuid' => 'u-2',
            'username' => 'alice',
            'email' => 'alice@example.com',
            'school_id' => null,
            'school_name' => null,
            'points' => 0,
            'is_admin' => 0,
            'avatar_url' => null,
            'lastlgn' => null,
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'email_verified_at' => null,
            'verification_code' => '123456',
            'verification_code_expires_at' => $futureExpiry,
            'verification_send_count' => 1,
            'verification_last_sent_at' => $lastSentAt
        ]);
        $updateStmt = $this->createMock(\PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $mockTurnstileService->method('verify')->with('test-turnstile')->willReturn(['success' => true]);
        $mockAuthService->method('generateToken')->willReturn('fake.jwt.token');
        $mockAuditLogService->expects($this->atLeastOnce())->method('log');
        $mockAuditLogService->expects($this->any())->method('logAuthOperation');
        $mockEmailService->expects($this->never())->method('sendVerificationCode');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/login', [
            'identifier' => 'alice@example.com',
            'password' => 'secret',
            'cf_turnstile_response' => 'test-turnstile',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('fake.jwt.token', $json['data']['token']);
        $this->assertEquals('alice', $json['data']['user']['username']);
        $this->assertTrue($json['data']['email_verification_required']);
        $this->assertFalse($json['data']['email_verification_sent']);
        $this->assertSame($futureExpiry, $json['data']['verification_expires_at']);
        $this->assertSame($resendAvailableAt, $json['data']['verification_resend_available_at']);
    }

    public function testResolveAvatarPrefersPublicUrl(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockR2Service->expects($this->once())
            ->method('getPublicUrl')
            ->with('avatars/default/avatar_01.png')
            ->willReturn('https://r2-dev.carbontrackapp.com/avatars/default/avatar_01.png');
        $mockR2Service->expects($this->never())->method('generatePresignedUrl');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $method = new \ReflectionMethod(AuthController::class, 'resolveAvatar');
        $method->setAccessible(true);
        $result = $method->invoke($controller, '/avatars/default/avatar_01.png');

        $this->assertSame('/avatars/default/avatar_01.png', $result['avatar_path']);
        $this->assertSame('https://r2-dev.carbontrackapp.com/avatars/default/avatar_01.png', $result['avatar_url']);
    }

    public function testForgotPasswordRequiresTurnstile(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockTurnstileService->expects($this->never())->method('verify');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/forgot-password', ['email' => 'john@example.com']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->forgotPassword($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('TURNSTILE_FAILED', $json['code']);
    }

    public function testSendVerificationCodeRequiresTurnstile(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockTurnstileService->expects($this->never())->method('verify');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/send-verification-code', ['email' => 'john@example.com']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->sendVerificationCode($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('TURNSTILE_FAILED', $json['code']);
    }

    public function testRegisterRejectsFailedTurnstileVerification(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockTurnstileService->expects($this->once())
            ->method('verify')
            ->with('bad-token')
            ->willReturn(['success' => false, 'error' => 'invalid-input-secret']);

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/register', [
            'username' => 'john',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'confirm_password' => 'secret123',
            'cf_turnstile_response' => 'bad-token',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->register($request, $response);
        $this->assertSame(400, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('TURNSTILE_FAILED', $json['code']);
    }

    public function testLoginRejectsFailedTurnstileVerification(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockTurnstileService->expects($this->once())
            ->method('verify')
            ->with('bad-token')
            ->willReturn(['success' => false, 'error' => 'invalid-input-secret']);

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/login', [
            'identifier' => 'john@example.com',
            'password' => 'secret123',
            'cf_turnstile_response' => 'bad-token',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertSame(400, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('TURNSTILE_FAILED', $json['code']);
    }

    public function testLoginRequiresTurnstileForWebRequests(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockTurnstileService->expects($this->never())->method('verify');

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/login', [
            'identifier' => 'john@example.com',
            'password' => 'secret123',
        ], null, ['Origin' => ['https://dev.carbontrackapp.com']]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertSame(400, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('TURNSTILE_FAILED', $json['code']);
    }

    public function testLoginReturns429WhenAccountIsLockedBeforeAnyVerification(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockAuthService->expects($this->once())
            ->method('isAccountLocked')
            ->with('locked@example.com', $this->isType('string'))
            ->willReturn(true);
        $mockAuthService->expects($this->never())->method('recordLoginAttempt');
        $mockAuthService->expects($this->never())->method('generateToken');
        $mockTurnstileService->expects($this->never())->method('verify');
        // Lockout audit must be persisted so operators can see brute-force shaping.
        $mockAuditLogService->expects($this->atLeastOnce())
            ->method('log')
            ->with($this->callback(function ($payload): bool {
                return is_array($payload)
                    && ($payload['action'] ?? null) === 'auth_login_locked';
            }));

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/login', [
            'identifier' => 'locked@example.com',
            'password' => 'secret123',
            'cf_turnstile_response' => 'tk',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertSame(429, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('ACCOUNT_LOCKED', $json['code']);
    }

    public function testLoginRecordsFailedAttemptAndReturns401OnWrongPassword(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);
        $mockRegion = $this->createMock(RegionService::class);

        $mockAuthService->expects($this->once())->method('isAccountLocked')->willReturn(false);
        $mockAuthService->expects($this->once())
            ->method('recordLoginAttempt')
            ->with('john', $this->isType('string'), false);
        $mockTurnstileService->method('verify')->with('tk')->willReturn(['success' => true]);

        $selectStmt = $this->createMock(\PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'id' => 11,
            'uuid' => 'u-11',
            'username' => 'john',
            'email' => 'john@example.com',
            'password_hash' => password_hash('correct-pass', PASSWORD_DEFAULT),
            'is_admin' => 0,
            'school_id' => null,
            'school_name' => null,
            'avatar_path' => null,
            'lastlgn' => null,
            'email_verified_at' => '2026-01-01 00:00:00',
        ]);
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($selectStmt);

        $controller = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $mockRegion
        );

        $request = makeRequest('POST', '/auth/login', [
            'identifier' => 'john',
            'password' => 'wrong-pass',
            'cf_turnstile_response' => 'tk',
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertSame(401, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('INVALID_CREDENTIALS', $json['code']);
    }

    public function testMobilePoWBypassDisabledWhenClientTokenEnvUnset(): void
    {
        $previousToken = $_ENV['MOBILE_CLIENT_TOKEN'] ?? null;
        unset($_ENV['MOBILE_CLIENT_TOKEN']);

        try {
            $mockAuthService = $this->createMock(AuthService::class);
            $mockTurnstileService = $this->createMock(TurnstileService::class);
            $mockTurnstileService->expects($this->once())
                ->method('verify')
                ->with('the-turnstile-token')
                ->willReturn(['success' => true]);
            $mockPowService = $this->createMock(ProofOfWorkService::class);
            $mockPowService->expects($this->never())->method('verify');

            $mockAuditLogService = $this->createMock(AuditLogService::class);
            $mockEmailService = $this->createMock(EmailService::class);
            $mockMessageService = $this->createMock(MessageService::class);
            $mockR2Service = $this->createMock(CloudflareR2Service::class);
            $mockLogger = $this->createMock(\Monolog\Logger::class);
            $mockRegion = $this->createMock(RegionService::class);

            $selectStmt = $this->createMock(\PDOStatement::class);
            $selectStmt->method('execute')->willReturn(true);
            $selectStmt->method('fetch')->willReturn(false);
            $mockPdo = $this->createMock(\PDO::class);
            $mockPdo->method('prepare')->willReturn($selectStmt);

            $controller = new AuthController(
                $mockAuthService,
                $mockEmailService,
                $mockTurnstileService,
                $mockAuditLogService,
                $mockMessageService,
                $mockR2Service,
                $mockLogger,
                $mockPdo,
                $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
                $mockRegion,
                null,
                null,
                $mockPowService
            );

            $request = makeRequest('POST', '/auth/login', [
                'identifier' => 'mobile@example.com',
                'password' => 'pw',
                'client_type' => 'mobile',
                'cf_turnstile_response' => 'the-turnstile-token',
            ], null, [
                'X-Client-Platform' => ['mobile'],
                'X-Mobile-Client-Token' => ['anything'],
            ]);
            $response = new \Slim\Psr7\Response();

            $resp = $controller->login($request, $response);
            // Identifier doesn't exist, so we expect 401 INVALID_CREDENTIALS — this proves
            // that the mobile-PoW bypass was *not* taken (the controller went through the
            // turnstile path and on into the user lookup).
            $this->assertSame(401, $resp->getStatusCode());
        } finally {
            if ($previousToken === null) {
                unset($_ENV['MOBILE_CLIENT_TOKEN']);
            } else {
                $_ENV['MOBILE_CLIENT_TOKEN'] = $previousToken;
            }
        }
    }

    public function testMobilePoWBypassDisabledWhenClientTokenWrong(): void
    {
        $previousToken = $_ENV['MOBILE_CLIENT_TOKEN'] ?? null;
        $_ENV['MOBILE_CLIENT_TOKEN'] = 'expected-secret';

        try {
            $mockAuthService = $this->createMock(AuthService::class);
            $mockTurnstileService = $this->createMock(TurnstileService::class);
            $mockTurnstileService->expects($this->once())
                ->method('verify')
                ->willReturn(['success' => false, 'error' => 'invalid-input-secret']);
            $mockPowService = $this->createMock(ProofOfWorkService::class);
            $mockPowService->expects($this->never())->method('verify');

            $controller = new AuthController(
                $mockAuthService,
                $this->createMock(EmailService::class),
                $mockTurnstileService,
                $this->createMock(AuditLogService::class),
                $this->createMock(MessageService::class),
                $this->createMock(CloudflareR2Service::class),
                $this->createMock(\Monolog\Logger::class),
                $this->createMock(\PDO::class),
                $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
                $this->createMock(RegionService::class),
                null,
                null,
                $mockPowService
            );

            $request = makeRequest('POST', '/auth/login', [
                'identifier' => 'mobile@example.com',
                'password' => 'pw',
                'client_type' => 'mobile',
                'cf_turnstile_response' => 'tk',
            ], null, [
                'X-Client-Platform' => ['mobile'],
                'X-Mobile-Client-Token' => ['wrong-secret'],
            ]);
            $resp = $controller->login($request, new \Slim\Psr7\Response());
            $this->assertSame(400, $resp->getStatusCode());
            $json = json_decode((string) $resp->getBody(), true);
            $this->assertSame('TURNSTILE_FAILED', $json['code']);
        } finally {
            if ($previousToken === null) {
                unset($_ENV['MOBILE_CLIENT_TOKEN']);
            } else {
                $_ENV['MOBILE_CLIENT_TOKEN'] = $previousToken;
            }
        }
    }

    public function testMobilePoWBypassUsedWhenClientTokenMatches(): void
    {
        $previousToken = $_ENV['MOBILE_CLIENT_TOKEN'] ?? null;
        $_ENV['MOBILE_CLIENT_TOKEN'] = 'expected-secret';

        try {
            $mockAuthService = $this->createMock(AuthService::class);
            $mockTurnstileService = $this->createMock(TurnstileService::class);
            $mockTurnstileService->expects($this->never())->method('verify');
            $mockPowService = $this->createMock(ProofOfWorkService::class);
            $mockPowService->expects($this->once())
                ->method('verify')
                ->with('challenge', '12345', 'auth.login')
                ->willReturn(['success' => true]);

            $selectStmt = $this->createMock(\PDOStatement::class);
            $selectStmt->method('execute')->willReturn(true);
            $selectStmt->method('fetch')->willReturn(false);
            $mockPdo = $this->createMock(\PDO::class);
            $mockPdo->method('prepare')->willReturn($selectStmt);

            $controller = new AuthController(
                $mockAuthService,
                $this->createMock(EmailService::class),
                $mockTurnstileService,
                $this->createMock(AuditLogService::class),
                $this->createMock(MessageService::class),
                $this->createMock(CloudflareR2Service::class),
                $this->createMock(\Monolog\Logger::class),
                $mockPdo,
                $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
                $this->createMock(RegionService::class),
                null,
                null,
                $mockPowService
            );

            $request = makeRequest('POST', '/auth/login', [
                'identifier' => 'mobile@example.com',
                'password' => 'pw',
                'client_type' => 'mobile',
                'pow_challenge' => 'challenge',
                'pow_nonce' => '12345',
            ], null, [
                'X-Client-Platform' => ['mobile'],
                'X-Mobile-Client-Token' => ['expected-secret'],
            ]);

            $resp = $controller->login($request, new \Slim\Psr7\Response());
            // Identifier won't be found in our stubbed PDO, so we expect a 401 from the
            // existing INVALID_CREDENTIALS branch - but reaching it proves PoW path ran.
            $this->assertSame(401, $resp->getStatusCode());
        } finally {
            if ($previousToken === null) {
                unset($_ENV['MOBILE_CLIENT_TOKEN']);
            } else {
                $_ENV['MOBILE_CLIENT_TOKEN'] = $previousToken;
            }
        }
    }

    public function testCreateProofOfWorkChallengeReturns429WhenIpRateLimited(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockPowService = $this->createMock(ProofOfWorkService::class);
        $mockPowService->expects($this->once())
            ->method('createChallenge')
            ->willThrowException(new \RuntimeException('Proof-of-work challenge rate limit exceeded'));

        $controller = new AuthController(
            $mockAuthService,
            $this->createMock(EmailService::class),
            $mockTurnstileService,
            $this->createMock(AuditLogService::class),
            $this->createMock(MessageService::class),
            $this->createMock(CloudflareR2Service::class),
            $this->createMock(\Monolog\Logger::class),
            $this->createMock(\PDO::class),
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $this->createMock(RegionService::class),
            null,
            null,
            $mockPowService
        );

        $request = makeRequest('POST', '/auth/pow/challenge', ['scope' => 'auth.login']);
        $resp = $controller->createProofOfWorkChallenge($request, new \Slim\Psr7\Response());
        $this->assertSame(429, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('POW_RATE_LIMITED', $json['code']);
    }

    public function testCreateProofOfWorkChallengePrefersCloudflareIpOverForwardedFor(): void
    {
        $previousTrustedProxies = $_ENV['TRUSTED_PROXY_CIDRS'] ?? null;
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_ENV['TRUSTED_PROXY_CIDRS'] = '198.51.100.0/24';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        $mockAuthService = $this->createMock(AuthService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockPowService = $this->createMock(ProofOfWorkService::class);
        $mockPowService->expects($this->once())
            ->method('createChallenge')
            ->with('auth.login', '203.0.113.9')
            ->willReturn([
                'challenge' => 'challenge',
                'difficulty' => 20,
                'expires_at' => '2026-05-17T00:00:00Z',
            ]);

        $controller = new AuthController(
            $mockAuthService,
            $this->createMock(EmailService::class),
            $mockTurnstileService,
            $this->createMock(AuditLogService::class),
            $this->createMock(MessageService::class),
            $this->createMock(CloudflareR2Service::class),
            $this->createMock(\Monolog\Logger::class),
            $this->createMock(\PDO::class),
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $this->createMock(RegionService::class),
            null,
            null,
            $mockPowService
        );

        try {
            $request = makeRequest('POST', '/auth/pow/challenge', ['scope' => 'auth.login'], null, [
                'X-Forwarded-For' => ['198.51.100.77'],
                'CF-Connecting-IP' => ['203.0.113.9'],
            ]);
            $resp = $controller->createProofOfWorkChallenge($request, new \Slim\Psr7\Response());

            $this->assertSame(200, $resp->getStatusCode());
        } finally {
            if ($previousTrustedProxies === null) {
                unset($_ENV['TRUSTED_PROXY_CIDRS']);
            } else {
                $_ENV['TRUSTED_PROXY_CIDRS'] = $previousTrustedProxies;
            }
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }
    }

    public function testCreateProofOfWorkChallengeUsesFirstUntrustedForwardedIpFromTrustedProxyChain(): void
    {
        $previousTrustedProxies = $_ENV['TRUSTED_PROXY_CIDRS'] ?? null;
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_ENV['TRUSTED_PROXY_CIDRS'] = '198.51.100.0/24';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';

        $mockAuthService = $this->createMock(AuthService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockPowService = $this->createMock(ProofOfWorkService::class);
        $mockPowService->expects($this->once())
            ->method('createChallenge')
            ->with('auth.login', '203.0.113.77')
            ->willReturn([
                'challenge' => 'challenge',
                'difficulty' => 20,
                'expires_at' => '2026-05-17T00:00:00Z',
            ]);

        $controller = new AuthController(
            $mockAuthService,
            $this->createMock(EmailService::class),
            $mockTurnstileService,
            $this->createMock(AuditLogService::class),
            $this->createMock(MessageService::class),
            $this->createMock(CloudflareR2Service::class),
            $this->createMock(\Monolog\Logger::class),
            $this->createMock(\PDO::class),
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
            $this->createMock(RegionService::class),
            null,
            null,
            $mockPowService
        );

        try {
            $request = makeRequest('POST', '/auth/pow/challenge', ['scope' => 'auth.login'], null, [
                'X-Forwarded-For' => ['203.0.113.250, 203.0.113.77, 198.51.100.20'],
            ]);
            $resp = $controller->createProofOfWorkChallenge($request, new \Slim\Psr7\Response());

            $this->assertSame(200, $resp->getStatusCode());
        } finally {
            if ($previousTrustedProxies === null) {
                unset($_ENV['TRUSTED_PROXY_CIDRS']);
            } else {
                $_ENV['TRUSTED_PROXY_CIDRS'] = $previousTrustedProxies;
            }
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }
    }

    public function testLoginRequiresProofOfWorkForMobileRequests(): void
    {
        $previousToken = $_ENV['MOBILE_CLIENT_TOKEN'] ?? null;
        $_ENV['MOBILE_CLIENT_TOKEN'] = 'expected-secret';

        try {
            $mockAuthService = $this->createMock(AuthService::class);
            $mockEmailService = $this->createMock(EmailService::class);
            $mockTurnstileService = $this->createMock(TurnstileService::class);
            $mockAuditLogService = $this->createMock(AuditLogService::class);
            $mockMessageService = $this->createMock(MessageService::class);
            $mockR2Service = $this->createMock(CloudflareR2Service::class);
            $mockLogger = $this->createMock(\Monolog\Logger::class);
            $mockPdo = $this->createMock(\PDO::class);
            $mockRegion = $this->createMock(RegionService::class);
            $mockPowService = $this->createMock(ProofOfWorkService::class);

            $mockTurnstileService->expects($this->never())->method('verify');
            $mockPowService->expects($this->once())
                ->method('verify')
                ->with(null, null, 'auth.login')
                ->willReturn(['success' => false, 'error' => 'missing-proof']);

            $controller = new AuthController(
                $mockAuthService,
                $mockEmailService,
                $mockTurnstileService,
                $mockAuditLogService,
                $mockMessageService,
                $mockR2Service,
                $mockLogger,
                $mockPdo,
                $this->createMock(\CarbonTrack\Services\ErrorLogService::class),
                $mockRegion,
                null,
                null,
                $mockPowService
            );

            $request = makeRequest('POST', '/auth/login', [
                'identifier' => 'john@example.com',
                'password' => 'secret123',
                'client_type' => 'mobile',
            ], null, [
                'X-Client-Platform' => ['mobile'],
                'X-Mobile-Client-Token' => ['expected-secret'],
            ]);
            $response = new \Slim\Psr7\Response();

            $resp = $controller->login($request, $response);
            $this->assertSame(400, $resp->getStatusCode());
            $json = json_decode((string) $resp->getBody(), true);
            $this->assertFalse($json['success']);
            $this->assertSame('POW_FAILED', $json['code']);
        } finally {
            if ($previousToken === null) {
                unset($_ENV['MOBILE_CLIENT_TOKEN']);
            } else {
                $_ENV['MOBILE_CLIENT_TOKEN'] = $previousToken;
            }
        }
    }
}
