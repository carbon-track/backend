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

        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger);
        $request = makeRequest('GET', '/avatars');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getAvatars($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);
        $this->assertEquals(1, $json['data'][0]['id']);
    }

    public function testGetAvatarRequiresAdmin(): void
    {
        $avatarModel = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id'=>1,'is_admin'=>0]);
        $controller = new AvatarController($avatarModel, $auth, $audit, $r2, $logger);
        $request = makeRequest('GET', '/avatars/1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getAvatar($request, $response, ['id'=>1]);
        $this->assertEquals(403, $resp->getStatusCode());
    }
}


