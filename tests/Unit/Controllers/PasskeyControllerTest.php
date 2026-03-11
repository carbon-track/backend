<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\PasskeyController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\PasskeyService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class PasskeyControllerTest extends TestCase
{
    public function testBeginRegistrationRequiresAuthentication(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(null);

        $controller = new PasskeyController(
            $authService,
            $this->createMock(PasskeyService::class),
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->beginRegistration(
            makeRequest('POST', '/users/me/passkeys/registration/options', ['label' => 'Laptop']),
            new Response()
        );

        $this->assertSame(401, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('UNAUTHORIZED', $payload['code']);
    }

    public function testBeginAuthenticationReturnsServicePayload(): void
    {
        $passkeyService = $this->createMock(PasskeyService::class);
        $passkeyService->expects($this->once())
            ->method('beginAuthentication')
            ->with(['identifier' => 'sarah@example.com'])
            ->willReturn([
                'challenge_id' => 'challenge-123',
                'public_key' => [
                    'challenge' => 'abc123',
                ],
            ]);

        $controller = new PasskeyController(
            $this->createMock(AuthService::class),
            $passkeyService,
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->beginAuthentication(
            makeRequest('POST', '/auth/passkey/login/options', ['identifier' => 'sarah@example.com']),
            new Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('challenge-123', $payload['data']['challenge_id']);
    }

    public function testCompleteAuthenticationReturnsJwtPayload(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->expects($this->once())
            ->method('generateToken')
            ->with($this->callback(static fn (array $user): bool => (int) $user['id'] === 5))
            ->willReturn('jwt-token');

        $passkeyService = $this->createMock(PasskeyService::class);
        $passkeyService->expects($this->once())
            ->method('completeAuthentication')
            ->with([
                'challenge_id' => 'challenge-123',
                'credential' => ['id' => 'cred-1'],
            ])
            ->willReturn([
                'user' => [
                    'id' => 5,
                    'username' => 'sarah',
                    'email' => 'sarah@example.com',
                    'points' => 10,
                    'is_admin' => false,
                ],
                'passkey' => [
                    'id' => 11,
                    'credential_id' => 'cred-1',
                ],
            ]);

        $controller = new PasskeyController(
            $authService,
            $passkeyService,
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->completeAuthentication(
            makeRequest('POST', '/auth/passkey/login/verify', [
                'challenge_id' => 'challenge-123',
                'credential' => ['id' => 'cred-1'],
            ]),
            new Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('jwt-token', $payload['data']['token']);
        $this->assertSame('sarah', $payload['data']['user']['username']);
        $this->assertSame('cred-1', $payload['data']['passkey']['credential_id']);
    }

    public function testUpdateReturnsUpdatedPasskey(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn([
            'id' => 5,
            'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
        ]);

        $passkeyService = $this->createMock(PasskeyService::class);
        $passkeyService->expects($this->once())
            ->method('updateLabelForUser')
            ->with([
                'id' => 5,
                'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
            ], 11, 'Office Key')
            ->willReturn([
                'id' => 11,
                'label' => 'Office Key',
                'credential_id' => 'cred-1',
            ]);

        $controller = new PasskeyController(
            $authService,
            $passkeyService,
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->update(
            makeRequest('PATCH', '/users/me/passkeys/11', ['label' => 'Office Key']),
            new Response(),
            ['id' => '11']
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Office Key', $payload['data']['passkey']['label']);
    }

    public function testAdminListRequiresAdminAccess(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 5, 'is_admin' => false]);
        $authService->method('isAdminUser')->willReturn(false);

        $controller = new PasskeyController(
            $authService,
            $this->createMock(PasskeyService::class),
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->adminList(
            makeRequest('GET', '/admin/passkeys'),
            new Response()
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('ACCESS_DENIED', $payload['code']);
    }

    public function testAdminStatsReturnsServicePayload(): void
    {
        $authService = $this->createMock(AuthService::class);
        $authService->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $authService->method('isAdminUser')->willReturn(true);

        $passkeyService = $this->createMock(PasskeyService::class);
        $passkeyService->expects($this->once())
            ->method('getAdminStats')
            ->with(1)
            ->willReturn([
                'users_with_passkeys' => 3,
                'total_active_passkeys' => 7,
                'new_passkeys_30d' => 2,
                'passkey_logins_7d' => 4,
                'passkey_logins_30d' => 9,
            ]);

        $controller = new PasskeyController(
            $authService,
            $passkeyService,
            $this->createMock(Logger::class),
            $this->createMock(ErrorLogService::class)
        );

        $response = $controller->adminStats(
            makeRequest('GET', '/admin/passkeys/stats'),
            new Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(7, $payload['data']['total_active_passkeys']);
    }
}
