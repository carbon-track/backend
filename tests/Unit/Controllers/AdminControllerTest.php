<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\AdminController;
use CarbonTrack\Services\BadgeService;

class AdminControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(AdminController::class));
    }

    public function testGetUsersRequiresAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 0]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = new AdminController($pdo, $auth, $audit, $badgeService);
        $request = makeRequest('GET', '/admin/users');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testGetUsersSuccessWithFilters(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);

        $capturedParams = [];

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturnCallback(function ($param, $value) use (&$capturedParams) {
                $capturedParams[$param] = $value;
                return true;
            });
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>1,'username'=>'u1','email'=>'u1@x.com','points'=>100]
        ]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('bindValue')->willReturn(true);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(1);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->withConsecutive(
                [
                    $this->callback(function ($sql) {
                        $this->assertStringContainsString('u.is_admin = :is_admin', $sql);
                        $this->assertStringContainsString('(u.username LIKE :search OR u.email LIKE :search)', $sql);
                        return true;
                    })
                ],
                [
                    $this->stringContains('COUNT(DISTINCT u.id)')
                ]
            )
            ->willReturnOnConsecutiveCalls($listStmt, $countStmt);

        $controller = new AdminController($pdo, $auth, $audit, $badgeService);
        $request = makeRequest('GET', '/admin/users', null, ['search' => 'u', 'status' => 'active', 'role' => 'user', 'sort' => 'points_desc']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total_items']);
        $this->assertEquals('u1', $json['data']['users'][0]['username']);
        $this->assertEquals('%u%', $capturedParams[':search'] ?? null);
        $this->assertEquals('active', $capturedParams[':status'] ?? null);
        $this->assertSame(0, $capturedParams[':is_admin'] ?? null);
    }

}


