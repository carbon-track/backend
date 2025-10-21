<?php

declare(strict_types=1);

use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\TurnstileService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class AuthEmailVerificationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                email TEXT,
                password TEXT,
                school_id INTEGER,
                is_admin INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                avatar_id INTEGER,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT,
                reset_token TEXT,
                reset_token_expires_at TEXT,
                email_verified_at TEXT,
                verification_code TEXT,
                verification_token TEXT,
                verification_code_expires_at TEXT,
                verification_attempts INTEGER DEFAULT 0,
                verification_send_count INTEGER DEFAULT 0,
                verification_last_sent_at TEXT,
                notification_email_mask INTEGER DEFAULT 0
            );
        ");
        $this->pdo->exec("CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT);");
        $this->pdo->exec("CREATE TABLE avatars (id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT);");
    }

    /**
     * @return array{controller: AuthController, email: EmailService&MockObject}
     */
    private function makeController(): array
    {
        /** @var AuthService&MockObject $auth */
        $auth = $this->createMock(AuthService::class);
        $auth->method('generateToken')->willReturn('test-jwt');
        /** @var EmailService&MockObject $email */
        $email = $this->createMock(EmailService::class);
        /** @var TurnstileService&MockObject $turnstile */
        $turnstile = $this->createMock(TurnstileService::class);
        $turnstile->method('verify')->willReturn(['success' => true]);
        /** @var AuditLogService&MockObject $audit */
        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logAuthOperation')->willReturn(true);
        /** @var MessageService&MockObject $msg */
        $msg = $this->createMock(MessageService::class);
        /** @var CloudflareR2Service&MockObject $r2 */
        $r2 = $this->createMock(CloudflareR2Service::class);
        /** @var ErrorLogService&MockObject $err */
        $err = $this->createMock(ErrorLogService::class);

        $logger = new Logger('test-email-verification');
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));

        $controller = new AuthController(
            $auth,
            $email,
            $turnstile,
            $audit,
            $msg,
            $r2,
            $logger,
            $this->pdo,
            $err
        );

        return ['controller' => $controller, 'email' => $email];
    }

    public function testSendVerificationCodeDispatchesEmail(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            INSERT INTO users (username, email, password, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ")->execute(['alice', 'alice@example.com', password_hash('Secret123!', PASSWORD_DEFAULT), $now, $now]);

        $setup = $this->makeController();
        $controller = $setup['controller'];
        $emailMock = $setup['email'];
        $emailMock->expects($this->once())
            ->method('sendVerificationCode')
            ->willReturn(true);

        $request = makeRequest('POST', '/auth/send-verification-code', ['email' => 'alice@example.com']);
        $response = $controller->sendVerificationCode($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('data', $payload);

        $row = $this->pdo->query("SELECT verification_token, verification_send_count FROM users WHERE email='alice@example.com'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['verification_token']);
        $this->assertSame(1, (int)$row['verification_send_count']);
    }

    public function testVerifyEmailWithTokenMarksUserVerified(): void
    {
        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(16));
        $this->pdo->prepare("
            INSERT INTO users (username, email, password, created_at, updated_at, verification_token, verification_code_expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            'bob',
            'bob@example.com',
            password_hash('Secret123!', PASSWORD_DEFAULT),
            $now,
            $now,
            $token,
            (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s')
        ]);

        $setup = $this->makeController();
        $controller = $setup['controller'];
        // Verification email not sent in this test, so no expectation on email mock

        $request = makeRequest('POST', '/auth/verify-email', ['token' => $token]);
        $response = $controller->verifyEmail($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('token', $payload['data']);
        $this->assertArrayHasKey('user', $payload['data']);
        $this->assertSame('bob@example.com', $payload['data']['user']['email']);
        $this->assertNotEmpty($payload['data']['user']['email_verified_at']);

        $row = $this->pdo->query("SELECT email_verified_at, verification_token FROM users WHERE email='bob@example.com'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['email_verified_at']);
        $this->assertNull($row['verification_token']);
    }
}
