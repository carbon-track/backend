<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\{AuthService, EmailService, TurnstileService, AuditLogService, ErrorLogService, MessageService, CloudflareR2Service};
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthRegistrationNewSchoolTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Minimal schema: users & schools
        $this->pdo->exec("CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT);");
        $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password TEXT, school_id INTEGER, is_admin INTEGER DEFAULT 0, points INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT, reset_token TEXT, reset_token_expires_at TEXT, email_verified_at TEXT, verification_code TEXT, verification_token TEXT, verification_code_expires_at TEXT, verification_attempts INTEGER DEFAULT 0, verification_send_count INTEGER DEFAULT 0, verification_last_sent_at TEXT);");
    }

    private function makeController(): AuthController
    {
        // Use PHPUnit mocks – acceptable in tests; underlying type hints allow subclass mocks
        /** @var AuthService&PHPUnit\Framework\MockObject\MockObject $auth */
        $auth = $this->createMock(AuthService::class);
        $auth->method('generateToken')->willReturn('fake-jwt');
        /** @var EmailService&PHPUnit\Framework\MockObject\MockObject $email */
        $email = $this->createMock(EmailService::class);
        $email->method('sendWelcomeEmail')->willReturn(true);
        $email->expects($this->once())->method('sendVerificationCode')->willReturn(true);
        /** @var TurnstileService&PHPUnit\Framework\MockObject\MockObject $turnstile */
        $turnstile = $this->createMock(TurnstileService::class);
    // TurnstileService::verify has return type array; mock must respect signature
    $turnstile->method('verify')->willReturn(['success' => true]);
        /** @var AuditLogService&PHPUnit\Framework\MockObject\MockObject $audit */
        $audit = $this->createMock(AuditLogService::class);
    $audit->method('logAuthOperation')->willReturn(true);
        /** @var MessageService&PHPUnit\Framework\MockObject\MockObject $msg */
        $msg = $this->createMock(MessageService::class);
        /** @var CloudflareR2Service&PHPUnit\Framework\MockObject\MockObject $r2 */
        $r2 = $this->createMock(CloudflareR2Service::class);
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));
        /** @var ErrorLogService&PHPUnit\Framework\MockObject\MockObject $err */
        $err = $this->createMock(ErrorLogService::class);

        return new AuthController($auth, $email, $turnstile, $audit, $msg, $r2, $logger, $this->pdo, $err);
    }

    private function makeRequest(array $body): Request
    {
        $factory = new ServerRequestFactory();
        $req = $factory->createServerRequest('POST', '/api/v1/auth/register');
        return $req->withParsedBody($body);
    }

    public function testRegistrationCreatesNewSchoolWhenOnlyNewSchoolNameProvided(): void
    {
        $controller = $this->makeController();
        $pwd = 'Password123!';
        $body = [
            'username' => 'user_new_school',
            'email' => 'user_new_school@example.com',
            'password' => $pwd,
            'confirm_password' => $pwd,
            'new_school_name' => 'Carbon Innovation Institute'
        ];
        $resp = new Response();
        $out = $controller->register($this->makeRequest($body), $resp);
        $data = json_decode((string)$out->getBody(), true);
        $this->assertEquals(201, $out->getStatusCode(), 'Should return 201 Created');
        $this->assertTrue($data['success']);
        // School should be created
        $stmt = $this->pdo->query("SELECT COUNT(*) AS c FROM schools WHERE LOWER(name)=LOWER('Carbon Innovation Institute')");
        $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertEquals(1, $count, 'School should have been inserted exactly once');
        // User should reference that school
        $stmt = $this->pdo->query("SELECT u.school_id, s.name FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE u.email='user_new_school@example.com'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row['school_id']);
        $this->assertEquals('Carbon Innovation Institute', $row['name']);
    }

    public function testRegistrationPrefersExistingSchoolWhenBothProvided(): void
    {
        // Seed an existing school
        $now = date('Y-m-d H:i:s');
        $this->pdo->exec("INSERT INTO schools (name, created_at, updated_at) VALUES ('Existing Academy', '$now', '$now')");
        $schoolId = (int)$this->pdo->lastInsertId();

        $controller = $this->makeController();
        $pwd = 'Password123!';
        $body = [
            'username' => 'user_existing_school',
            'email' => 'user_existing_school@example.com',
            'password' => $pwd,
            'confirm_password' => $pwd,
            'school_id' => $schoolId,
            'new_school_name' => 'Another New School Name' // should be ignored
        ];
        $resp = new Response();
        $out = $controller->register($this->makeRequest($body), $resp);
        $data = json_decode((string)$out->getBody(), true);
        $this->assertEquals(201, $out->getStatusCode());
        $this->assertTrue($data['success']);
        // Ensure no new school was inserted with the new name
        $stmt = $this->pdo->query("SELECT COUNT(*) AS c FROM schools WHERE LOWER(name)=LOWER('Another New School Name')");
        $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
        $this->assertEquals(0, $count, 'Should not create a new school when school_id is provided');
        // User should reference original school id
        $stmt = $this->pdo->query("SELECT school_id FROM users WHERE email='user_existing_school@example.com'");
        $userSchool = (int)$stmt->fetch(PDO::FETCH_ASSOC)['school_id'];
        $this->assertEquals($schoolId, $userSchool);
    }
}
