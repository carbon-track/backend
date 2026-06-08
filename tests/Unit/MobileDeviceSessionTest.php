<?php

declare(strict_types=1);

use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\ProofOfWorkService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\TurnstileService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class MobileDeviceSessionTest extends TestCase
{
    private PDO $pdo;
    private AuthService $authService;
    private ?string $previousMobileClientToken;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

        $this->pdo->exec('CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE avatars (id INTEGER PRIMARY KEY AUTOINCREMENT, file_path TEXT)');
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                username TEXT,
                email TEXT,
                password TEXT,
                password_hash TEXT,
                school_id INTEGER,
                avatar_id INTEGER,
                role TEXT DEFAULT \'user\',
                is_admin INTEGER DEFAULT 0,
                is_support INTEGER DEFAULT 0,
                points INTEGER DEFAULT 0,
                region_code TEXT,
                email_verified_at TEXT,
                status TEXT,
                token_version INTEGER NOT NULL DEFAULT 0,
                lastlgn TEXT,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT,
                ip_address TEXT,
                success INTEGER,
                attempted_at TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE mobile_device_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                refresh_token_hash TEXT NOT NULL UNIQUE,
                previous_refresh_token_hash TEXT,
                device_id TEXT,
                device_name TEXT,
                platform TEXT,
                user_agent TEXT,
                ip_address TEXT,
                expires_at TEXT NOT NULL,
                last_used_at TEXT,
                revoked_at TEXT,
                revoked_reason TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->authService = new AuthService('test-device-session-secret-with-enough-length', 'HS256', 60);
        $this->authService->setDatabase($this->pdo);

        $this->previousMobileClientToken = $_ENV['MOBILE_CLIENT_TOKEN'] ?? null;
        $_ENV['MOBILE_CLIENT_TOKEN'] = 'test-mobile-client-token';
    }

    protected function tearDown(): void
    {
        if ($this->previousMobileClientToken === null) {
            unset($_ENV['MOBILE_CLIENT_TOKEN']);
        } else {
            $_ENV['MOBILE_CLIENT_TOKEN'] = $this->previousMobileClientToken;
        }
    }

    public function testMobileLoginCreatesDeviceSessionAndReturnsRefreshToken(): void
    {
        $this->seedUser();

        $response = $this->makeController()->login(makeRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'mobile@example.com',
            'password' => 'Password123!',
            'client_type' => 'mobile',
            'device_id' => 'ios-device-1',
            'device_name' => 'Jeffery iPhone',
            'platform' => 'ios',
            'pow_challenge' => 'challenge',
            'pow_nonce' => '12345',
        ], null, [
            'User-Agent' => ['CarbonTrack Mobile Test'],
            'X-Client-Platform' => ['mobile'],
            'X-Mobile-Client-Token' => ['test-mobile-client-token'],
        ]), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['data']['token']);
        $this->assertNotEmpty($payload['data']['refresh_token']);
        $this->assertSame('bearer', $payload['data']['refresh_token_type']);
        $this->assertGreaterThan(0, $payload['data']['refresh_expires_in']);
        $this->assertSame('ios-device-1', $payload['data']['device_session']['device_id']);

        $session = $this->pdo->query('SELECT * FROM mobile_device_sessions')->fetch();
        $this->assertSame(1, (int) $session['user_id']);
        $this->assertSame('ios-device-1', $session['device_id']);
        $this->assertNotSame($payload['data']['refresh_token'], $session['refresh_token_hash']);
        $this->assertNull($session['revoked_at']);
    }

    public function testRefreshTokenRotatesDeviceSessionAndRejectsReusedToken(): void
    {
        $this->seedUser();
        $loginPayload = $this->loginMobile();
        $originalRefreshToken = $loginPayload['data']['refresh_token'];

        $refreshResponse = $this->makeController()->refresh(makeRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]), new Response());

        $this->assertSame(200, $refreshResponse->getStatusCode());
        $refreshPayload = json_decode((string) $refreshResponse->getBody(), true);
        $this->assertTrue($refreshPayload['success']);
        $this->assertNotEmpty($refreshPayload['data']['token']);
        $this->assertNotEmpty($refreshPayload['data']['refresh_token']);
        $this->assertNotSame($originalRefreshToken, $refreshPayload['data']['refresh_token']);

        $this->pdo->exec("UPDATE mobile_device_sessions SET updated_at = '2000-01-01 00:00:00'");

        $reuseResponse = $this->makeController()->refresh(makeRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]), new Response());

        $this->assertSame(401, $reuseResponse->getStatusCode());
        $reusePayload = json_decode((string) $reuseResponse->getBody(), true);
        $this->assertSame('INVALID_REFRESH_TOKEN', $reusePayload['code']);
    }

    public function testRecentlyRotatedRefreshTokenRetryUsesGracePeriod(): void
    {
        $this->seedUser();
        $loginPayload = $this->loginMobile();
        $originalRefreshToken = $loginPayload['data']['refresh_token'];

        $firstResponse = $this->makeController()->refresh(makeRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]), new Response());
        $this->assertSame(200, $firstResponse->getStatusCode());

        $retryResponse = $this->makeController()->refresh(makeRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]), new Response());

        $this->assertSame(200, $retryResponse->getStatusCode());
        $retryPayload = json_decode((string) $retryResponse->getBody(), true);
        $this->assertTrue($retryPayload['success']);
        $this->assertNotEmpty($retryPayload['data']['refresh_token']);
        $this->assertNotSame($originalRefreshToken, $retryPayload['data']['refresh_token']);
        $this->assertNull($this->pdo->query('SELECT revoked_at FROM mobile_device_sessions')->fetchColumn());

        $replayResponse = $this->makeController()->refresh(makeRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ]), new Response());
        $this->assertSame(401, $replayResponse->getStatusCode());
    }

    public function testBrowserSpoofedPlatformDoesNotReceiveMobileRefreshToken(): void
    {
        $this->seedUser();

        $response = $this->makeController()->login(makeRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'mobile@example.com',
            'password' => 'Password123!',
            'platform' => 'ios',
            'cf_turnstile_response' => 'turnstile-ok',
        ], null, [
            'Origin' => ['https://example.test'],
            'User-Agent' => ['Mozilla/5.0'],
        ]), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertArrayNotHasKey('refresh_token', $payload['data']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mobile_device_sessions')->fetchColumn());
    }

    public function testLogoutRevokesMatchingMobileDeviceSession(): void
    {
        $this->seedUser();
        $loginPayload = $this->loginMobile();

        $response = $this->makeController()->logout(makeRequest('POST', '/api/v1/auth/logout', [
            'refresh_token' => $loginPayload['data']['refresh_token'],
        ], null, [
            'Authorization' => ['Bearer ' . $loginPayload['data']['token']],
        ]), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $revokedAt = $this->pdo->query('SELECT revoked_at FROM mobile_device_sessions')->fetchColumn();
        $this->assertNotEmpty($revokedAt);
    }

    private function loginMobile(): array
    {
        $response = $this->makeController()->login(makeRequest('POST', '/api/v1/auth/login', [
            'identifier' => 'mobile@example.com',
            'password' => 'Password123!',
            'client_type' => 'mobile',
            'device_id' => 'ios-device-1',
            'device_name' => 'Jeffery iPhone',
            'platform' => 'ios',
            'pow_challenge' => 'challenge',
            'pow_nonce' => '12345',
        ], null, [
            'X-Client-Platform' => ['mobile'],
            'X-Mobile-Client-Token' => ['test-mobile-client-token'],
        ]), new Response());

        return json_decode((string) $response->getBody(), true);
    }

    private function seedUser(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (uuid, username, email, password_hash, role, points, region_code, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            '22222222-2222-4222-8222-222222222222',
            'mobile_user',
            'mobile@example.com',
            password_hash('Password123!', PASSWORD_DEFAULT),
            'user',
            9,
            'US-CA',
            $now,
            $now,
            $now,
        ]);
    }

    private function makeController(): AuthController
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->method('log')->willReturn(true);
        $audit->method('logAuthOperation')->willReturn(true);

        $logger = new Logger('mobile-device-session-test');
        $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));

        $turnstile = $this->createMock(TurnstileService::class);
        $turnstile->method('verify')->with('turnstile-ok')->willReturn(['success' => true]);

        $proofOfWork = $this->createMock(ProofOfWorkService::class);
        $proofOfWork->method('verify')
            ->with('challenge', '12345', $this->anything())
            ->willReturn(['success' => true]);

        return new AuthController(
            $this->authService,
            $this->createMock(EmailService::class),
            $turnstile,
            $audit,
            $this->createMock(MessageService::class),
            $this->createMock(CloudflareR2Service::class),
            $logger,
            $this->pdo,
            $this->createMock(ErrorLogService::class),
            new RegionService(null, null),
            null,
            null,
            $proofOfWork
        );
    }
}
