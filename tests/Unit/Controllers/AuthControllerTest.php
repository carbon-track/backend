<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\CloudflareR2Service;
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

        $authController = new AuthController(
            $mockAuthService,
            $mockEmailService,
            $mockTurnstileService,
            $mockAuditLogService,
            $mockMessageService,
            $mockR2Service,
            $mockLogger,
            $mockPdo,
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class)
        );

        $this->assertInstanceOf(AuthController::class, $authController);
    }

    public function testAuthControllerHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(AuthController::class, 'register'));
        $this->assertTrue(method_exists(AuthController::class, 'login'));
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
        
        $meMethod = $reflection->getMethod('me');
        $this->assertTrue($meMethod->isPublic());
    }

    public function testAuthControllerHasCorrectDependencies(): void
    {
        $reflection = new \ReflectionClass(AuthController::class);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        $this->assertCount(9, $parameters);

        $expectedTypes = [
            'CarbonTrack\Services\AuthService',
            'CarbonTrack\Services\EmailService',
            'CarbonTrack\Services\TurnstileService',
            'CarbonTrack\Services\AuditLogService',
            'CarbonTrack\Services\MessageService',
            'CarbonTrack\Services\CloudflareR2Service',
            'Monolog\Logger',
            'PDO',
            'CarbonTrack\Services\ErrorLogService'
        ];
        $nullableIndexes = [5, 8];

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
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class)
        );

        $request = makeRequest('POST', '/login', ['username' => 'john', 'password' => 'secret']);
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

    public function testLoginDoesNotResendWhenVerificationStillValid(): void
    {
        $mockAuthService = $this->createMock(AuthService::class);
        $mockEmailService = $this->createMock(EmailService::class);
        $mockTurnstileService = $this->createMock(TurnstileService::class);
        $mockAuditLogService = $this->createMock(AuditLogService::class);
        $mockMessageService = $this->createMock(MessageService::class);
        $mockR2Service = $this->createMock(CloudflareR2Service::class);
        $mockLogger = $this->createMock(\Monolog\Logger::class);

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
            'verification_code_expires_at' => '2025-01-01 00:30:00',
            'verification_send_count' => 1,
            'verification_last_sent_at' => '2025-01-01 00:00:00'
        ]);
        $updateStmt = $this->createMock(\PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

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
            $this->createMock(\CarbonTrack\Services\ErrorLogService::class)
        );

        $request = makeRequest('POST', '/login', ['identifier' => 'alice@example.com', 'password' => 'secret']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->login($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('fake.jwt.token', $json['data']['token']);
        $this->assertEquals('alice', $json['data']['user']['username']);
        $this->assertTrue($json['data']['email_verification_required']);
        $this->assertFalse($json['data']['email_verification_sent']);
        $this->assertSame('2025-01-01 00:30:00', $json['data']['verification_expires_at']);
        $this->assertSame('2025-01-01 01:00:00', $json['data']['verification_resend_available_at']);
    }
}
