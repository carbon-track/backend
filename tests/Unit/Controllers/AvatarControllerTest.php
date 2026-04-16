<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\AvatarController;

class AvatarControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(AvatarController::class));
    }

    public function testGetAvatarsHidesInactiveForNonAdmin(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id'=>1,'is_admin'=>0]);
        $avatarModel->method('getAvailableAvatars')->willReturn([
            ['id'=>1,'name'=>'A','is_active'=>1]
        ]);

    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    /** @var \CarbonTrack\Models\Avatar $avatarModel */
    /** @var \CarbonTrack\Services\AuthService $auth */
    /** @var \CarbonTrack\Services\AuditLogService $audit */
    /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
    /** @var \Monolog\Logger $logger */
    /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
    $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);
        $request = makeRequest('GET', '/avatars');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getAvatars($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);
        $this->assertEquals(1, $json['data'][0]['id']);
    }

    public function testGetAvatarsAllowsAdminToIncludeInactiveAndReturnsActivationState(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'is_admin' => 1]);
        $avatarModel->expects($this->once())
            ->method('getAvailableAvatars')
            ->with('animals', true)
            ->willReturn([
                ['id' => 1, 'name' => 'Cat', 'category' => 'animals', 'is_active' => '1', 'is_default' => '0'],
                ['id' => 2, 'name' => 'Fox', 'category' => 'animals', 'is_active' => '0', 'is_default' => '1'],
            ]);

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->getAvatars(
            makeRequest('GET', '/admin/avatars', null, ['category' => 'animals', 'include_inactive' => '1']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']);
        $this->assertTrue($payload['data'][0]['is_active']);
        $this->assertFalse($payload['data'][1]['is_active']);
        $this->assertTrue($payload['data'][1]['is_default']);
    }

    public function testGetAvatarsIncludesIconUrls(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(null);
        $avatarModel->method('getAvailableAvatars')->willReturn([
            [
                'id' => 10,
                'name' => 'Default Avatar',
                'file_path' => '/avatars/default/avatar.png',
                'thumbnail_path' => 'avatars/default/avatar-thumb.png',
                'is_active' => 1,
            ],
        ]);

        $r2->expects($this->exactly(2))
            ->method('getPublicUrl')
            ->withConsecutive(
                ['avatars/default/avatar.png'],
                ['avatars/default/avatar-thumb.png']
            )
            ->willReturnOnConsecutiveCalls(
                'https://cdn.example/avatar.png',
                'https://cdn.example/avatar-thumb.png'
            );

        $r2->expects($this->once())
            ->method('generatePresignedUrl')
            ->with('avatars/default/avatar.png', 600)
            ->willReturn('https://signed.example/avatar.png');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->getAvatars(makeRequest('GET', '/avatars'), new \Slim\Psr7\Response());
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['data']);
        $avatar = $payload['data'][0];
        $this->assertSame('avatars/default/avatar.png', $avatar['icon_path']);
        $this->assertSame('https://cdn.example/avatar.png', $avatar['icon_url']);
        $this->assertSame('https://signed.example/avatar.png', $avatar['icon_presigned_url']);
        $this->assertSame('https://cdn.example/avatar.png', $avatar['image_url']);
        $this->assertSame('https://cdn.example/avatar.png', $avatar['url']);
        $this->assertSame('https://cdn.example/avatar-thumb.png', $avatar['thumbnail_url']);
    }

    public function testGetAvatarRequiresAdmin(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id'=>1,'is_admin'=>0]);
    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    /** @var \CarbonTrack\Models\Avatar $avatarModel */
    /** @var \CarbonTrack\Services\AuthService $auth */
    /** @var \CarbonTrack\Services\AuditLogService $audit */
    /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
    /** @var \Monolog\Logger $logger */
    /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
    $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);
        $request = makeRequest('GET', '/avatars/1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getAvatar($request, $response, ['id'=>1]);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testUpdateAvatarNormalizesEmptyStringDefaultFlag(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $audit->method('log')->willReturn(true);

        $existingAvatar = [
            'id' => 5,
            'name' => 'Original Avatar',
            'file_path' => '/avatars/original.png',
            'is_default' => 1,
        ];
        $updatedAvatar = [
            'id' => 5,
            'name' => 'Original Avatar',
            'file_path' => '/avatars/original.png',
            'is_default' => 0,
        ];

        $avatarModel->expects($this->exactly(2))
            ->method('getAvatarById')
            ->with(5)
            ->willReturnOnConsecutiveCalls($existingAvatar, $updatedAvatar);
        $avatarModel->expects($this->never())->method('setDefaultAvatar');
        $avatarModel->expects($this->once())
            ->method('updateAvatar')
            ->with(5, $this->callback(function (array $data): bool {
                $this->assertArrayHasKey('is_default', $data);
                $this->assertFalse($data['is_default']);
                return true;
            }))
            ->willReturn(true);

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $request = makeRequest('PUT', '/admin/avatars/5', [
            'is_default' => '',
        ]);
        $response = $controller->updateAvatar($request, new \Slim\Psr7\Response(), ['id' => 5]);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['data']['is_default']);
    }

    public function testUpdateAvatarRejectsInvalidSortOrderString(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->never())->method('updateAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->updateAvatar(
            makeRequest('PUT', '/admin/avatars/5', ['sort_order' => 'abc']),
            new \Slim\Psr7\Response(),
            ['id' => 5]
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
    }

    public function testCreateAvatarRejectsNonObjectRequestBody(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->never())->method('createAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->createAvatar(
            makeRequest('POST', '/admin/avatars', null),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('INVALID_REQUEST_BODY', $payload['code']);
    }

    public function testCreateAvatarRejectsInactiveDefaultAvatar(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->never())->method('createAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->createAvatar(
            makeRequest('POST', '/admin/avatars', [
                'name' => 'Broken Default',
                'file_path' => '/avatars/broken.png',
                'is_default' => true,
                'is_active' => false,
            ]),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
        $this->assertSame('Default avatar must remain active', $payload['message']);
    }

    public function testUpdateAvatarRejectsDisablingDefaultAvatar(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->once())
            ->method('getAvatarById')
            ->with(5)
            ->willReturn([
                'id' => 5,
                'name' => 'Default Avatar',
                'file_path' => '/avatars/default.png',
                'is_default' => 1,
                'is_active' => 1,
            ]);
        $avatarModel->expects($this->never())->method('updateAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->updateAvatar(
            makeRequest('PUT', '/admin/avatars/5', ['is_active' => false]),
            new \Slim\Psr7\Response(),
            ['id' => 5]
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
        $this->assertSame('Default avatar must remain active', $payload['message']);
    }

    public function testUpdateAvatarRejectsDisablingCurrentDefaultEvenWhenPayloadClearsDefault(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->once())
            ->method('getAvatarById')
            ->with(5)
            ->willReturn([
                'id' => 5,
                'name' => 'Default Avatar',
                'file_path' => '/avatars/default.png',
                'is_default' => 1,
                'is_active' => 1,
            ]);
        $avatarModel->expects($this->never())->method('getDefaultAvatar');
        $avatarModel->expects($this->never())->method('updateAvatarAndReassignUsers');
        $avatarModel->expects($this->never())->method('updateAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->updateAvatar(
            makeRequest('PUT', '/admin/avatars/5', ['is_default' => false, 'is_active' => false]),
            new \Slim\Psr7\Response(),
            ['id' => 5]
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
        $this->assertSame('Default avatar must remain active', $payload['message']);
    }

    public function testUpdateAvatarDisablesAvatarReassignsUsersAndSendsNotifications(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 7, 'is_admin' => 1]);
        $audit->method('log')->willReturn(true);
        $avatarModel->expects($this->exactly(2))
            ->method('getAvatarById')
            ->with(5)
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 5,
                    'name' => 'Seasonal Fox',
                    'file_path' => '/avatars/fox.png',
                    'is_default' => 0,
                    'is_active' => 1,
                ],
                [
                    'id' => 5,
                    'name' => 'Seasonal Fox',
                    'file_path' => '/avatars/fox.png',
                    'is_default' => 0,
                    'is_active' => 0,
                ]
            );
        $avatarModel->expects($this->once())
            ->method('getDefaultAvatar')
            ->willReturn([
                'id' => 1,
                'name' => 'Default Seedling',
                'file_path' => '/avatars/default.png',
                'is_default' => 1,
            ]);
        $avatarModel->expects($this->once())
            ->method('updateAvatarAndReassignUsers')
            ->with(5, ['is_active' => false], 1)
            ->willReturn([
                'reassigned_user_count' => 2,
                'users' => [
                    ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
                    ['id' => 202, 'username' => 'bob', 'email' => 'bob@example.com'],
                ],
            ]);
        $avatarModel->expects($this->never())->method('updateAvatar');

        $messageService->expects($this->exactly(2))
            ->method('sendSystemMessage')
            ->with(
                $this->logicalOr($this->equalTo(101), $this->equalTo(202)),
                $this->stringContains('Selected avatar unavailable'),
                $this->stringContains('Default Seedling'),
                \CarbonTrack\Models\Message::TYPE_NOTIFICATION,
                \CarbonTrack\Models\Message::PRIORITY_NORMAL,
                'avatar',
                5,
                true
            );

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        /** @var \CarbonTrack\Services\MessageService $messageService */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog, $messageService);

        $response = $controller->updateAvatar(
            makeRequest('PUT', '/admin/avatars/5', ['is_active' => false]),
            new \Slim\Psr7\Response(),
            ['id' => 5]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['data']['is_active']);
    }

    public function testUpdateAvatarRejectsDisablingAvatarWhenNoDefaultFallbackExists(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $avatarModel->expects($this->once())
            ->method('getAvatarById')
            ->with(5)
            ->willReturn([
                'id' => 5,
                'name' => 'Seasonal Fox',
                'file_path' => '/avatars/fox.png',
                'is_default' => 0,
                'is_active' => 1,
            ]);
        $avatarModel->expects($this->once())
            ->method('getDefaultAvatar')
            ->willReturn(null);
        $avatarModel->expects($this->once())
            ->method('updateAvatarAndReassignUsers')
            ->with(5, ['is_active' => false], null)
            ->willThrowException(new \RuntimeException('DEFAULT_AVATAR_REQUIRED'));
        $avatarModel->expects($this->never())->method('updateAvatar');

        /** @var \CarbonTrack\Models\Avatar $avatarModel */
        /** @var \CarbonTrack\Services\AuthService $auth */
        /** @var \CarbonTrack\Services\AuditLogService $audit */
        /** @var \CarbonTrack\Services\CloudflareR2Service $r2 */
        /** @var \Monolog\Logger $logger */
        /** @var \CarbonTrack\Services\ErrorLogService $errorLog */
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger, $errorLog);

        $response = $controller->updateAvatar(
            makeRequest('PUT', '/admin/avatars/5', ['is_active' => false]),
            new \Slim\Psr7\Response(),
            ['id' => 5]
        );

        $this->assertSame(409, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('DEFAULT_AVATAR_REQUIRED', $payload['code']);
    }
}


