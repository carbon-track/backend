<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\AdminController;

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

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 0]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = new AdminController($pdo, $auth, $audit);
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

        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);
        // audit->log is a concrete method; don't mock expectations to avoid final/static issues

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>1,'username'=>'u1','email'=>'u1@x.com','points'=>100]
        ]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('bindValue')->willReturn(true);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(1);

        // First prepare for list, then for count
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($listStmt, $countStmt);

        $controller = new AdminController($pdo, $auth, $audit);
        $request = makeRequest('GET', '/admin/users', null, ['search' => 'u', 'status' => 'active', 'role' => 'user', 'sort' => 'points_desc']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total_items']);
        $this->assertEquals('u1', $json['data']['users'][0]['username']);
    }
}


