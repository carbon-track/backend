<?php

declare(strict_types=1);

use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\TurnstileService;
use Firebase\JWT\JWT;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AuthRefreshTest extends TestCase
{
    private PDO $pdo;
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT);");
        $this->pdo->exec("CREATE TABLE avatars (id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT);");
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                username TEXT,
                email TEXT,
                password TEXT,
                school_id INTEGER,
                avatar_id INTEGER,
                role TEXT DEFAULT 'user',
                is_admin INTEGER DEFAULT 0,
                is_support INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                region_code TEXT,
                email_verified_at TEXT,
                status TEXT,
                lastlgn TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            );
        ");

        $this->authService = new AuthService('test-refresh-secret-with-enough-length', 'HS256', 60);
    }

    private function makeController(?AuditLogService $audit = null): AuthController
    {
        $email = $this->createMock(EmailService::class);
        $turnstile = $this->createMock(TurnstileService::class);
        if ($audit === null) {
            $audit = $this->createMock(AuditLogService::class);
            $audit->method('logAuthOperation')->willReturn(true);
        }
        $message = $this->createMock(MessageService::class);
        $r2 = $this->createMock(CloudflareR2Service::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $region = new RegionService(null, null);
        $logger = new Logger('auth-refresh-test');
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));

        return new AuthController(
            $this->authService,
            $email,
            $turnstile,
            $audit,
            $message,
            $r2,
            $logger,
            $this->pdo,
            $errorLog,
            $region
        );
    }

    private function seedUser(): array
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            INSERT INTO users (uuid, username, email, password, role, points, region_code, email_verified_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            '11111111-1111-4111-8111-111111111111',
            'refresh_user',
            'refresh@example.com',
            password_hash('Password123!', PASSWORD_DEFAULT),
            'user',
            7,
            'CN-GD',
            $now,
            $now,
            $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->pdo->query("SELECT * FROM users WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    private function makeRequest(?string $token, string $path = '/api/v1/auth/refresh'): Request
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        return $request;
    }

    public function testRefreshReturnsTokenUserAndExpiryForValidToken(): void
    {
        $user = $this->seedUser();
        $token = $this->authService->generateToken($user);
        $response = $this->makeController()->refresh($this->makeRequest($token), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['data']['token']);
        $this->assertSame('refresh_user', $payload['data']['user']['username']);
        $this->assertIsInt($payload['data']['expires_in']);
    }

    public function testRefreshAcceptsLegacySubjectOnlyToken(): void
    {
        $user = $this->seedUser();
        $now = time();
        $token = JWT::encode([
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => $now,
            'exp' => $now + 60,
            'sub' => (string) $user['id'],
        ], 'test-refresh-secret-with-enough-length', 'HS256');

        $response = $this->makeController()->refresh($this->makeRequest($token), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotSame($token, $payload['data']['token']);
        $this->assertSame('refresh_user', $payload['data']['user']['username']);
    }

    public function testRefreshRequiresAuthorizationHeader(): void
    {
        $response = $this->makeController()->refresh($this->makeRequest(null), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('AUTH_REQUIRED', $payload['code']);
    }

    public function testRefreshAuditsMissingAuthorization(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('logAuthOperation')
            ->with(
                'token_refresh',
                null,
                false,
                $this->callback(static fn (array $context): bool => (
                    ($context['request_data']['code'] ?? null) === 'AUTH_REQUIRED'
                ))
            )
            ->willReturn(true);

        $response = $this->makeController($audit)->refresh($this->makeRequest(null), new Response());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRefreshRejectsInvalidToken(): void
    {
        $response = $this->makeController()->refresh($this->makeRequest('not-a-jwt'), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame('INVALID_TOKEN', $payload['code']);
    }

}
