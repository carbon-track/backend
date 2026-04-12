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
        $this->assertSame(0, $payload['data']['is_default']);
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
}


