<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use CarbonTrack\Controllers\SystemLogController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use PHPUnit\Framework\TestCase;

class SystemLogControllerTest extends TestCase
{
    public function testListUsesDistinctGeneralSearchBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $auth = $this->createMock(AuthService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);
        $bound = [];

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'value';
        $countStmt->checkParameters = true;
        $countStmt->expects($this->exactly(9))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$bound) {
                $bound['count'][$key] = [$value, $type];
                return true;
            });
        $countStmt->expects($this->once())->method('bindings')->willReturn(true);
        $countStmt->expects($this->once())->method('value')->willReturn(0);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->expects($this->exactly(11))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$bound) {
                $bound['list'][$key] = [$value, $type];
                return true;
            });
        $listStmt->expects($this->once())->method('bindings')->willReturn(true);
        $listStmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use ($countStmt, $listStmt) {
                static $prepareCalls = 0;
                $prepareCalls++;
                $this->assertStringContainsString('request_id LIKE :q_request_id', $sql);
                $this->assertStringContainsString('path LIKE :q_path', $sql);
                $this->assertStringContainsString('server_meta LIKE :q_server_meta', $sql);
                return $prepareCalls === 1 ? $countStmt : $listStmt;
            });

        $controller = new SystemLogController($connection, $auth, $audit);
        $request = makeRequest('GET', '/admin/system-logs', null, ['q' => 'trace']);
        $response = new \Slim\Psr7\Response();

        $result = $controller->list($request, $response);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame('%trace%', $bound['count'][':q_request_id'][0] ?? null);
        $this->assertSame('%trace%', $bound['count'][':q_path'][0] ?? null);
        $this->assertSame('%trace%', $bound['count'][':q_server_meta'][0] ?? null);
        $this->assertSame(20, $bound['list'][':limit'][0] ?? null);
        $this->assertSame(0, $bound['list'][':offset'][0] ?? null);
    }
}
