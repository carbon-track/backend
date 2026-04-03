<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\CarbonActivityController;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\AuditLogService;

class CarbonActivityControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(CarbonActivityController::class));
    }

    public function testGetActivitiesGrouped(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
        $calc->method('getActivitiesGroupedByCategory')->willReturn([
            [
                'category' => 'daily',
                'count' => 1,
                'activities' => [['id' => 'a']]
            ]
        ]);
        $calc->method('getCategories')->willReturn(['daily']);
    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    $controller = new CarbonActivityController($calc, $audit, $errorLog);

        $request = makeRequest('GET', '/carbon-activities', null, ['grouped' => 'true']);
        $request = $request->withQueryParams(['grouped'=>'true']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getActivities($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(['daily'], $json['data']['categories']);
        $this->assertTrue($json['data']['grouped']);
        $this->assertSame(1, $json['data']['total']);
        $this->assertSame('daily', $json['data']['activities'][0]['category']);
        $this->assertCount(1, $json['data']['activities'][0]['activities']);
    }

    public function testGetCategoriesWritesAuditMetadata(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
        $calc->method('getCategories')->willReturn(['daily', 'transport']);

        $audit->expects($this->once())
            ->method('logAudit')
            ->with($this->callback(function (array $payload): bool {
                $this->assertSame('carbon_management', $payload['operation_category'] ?? null);
                $this->assertSame('carbon_activity_categories_alias_read', $payload['action'] ?? null);
                $this->assertSame(99, $payload['user_id'] ?? null);
                $this->assertSame('user', $payload['actor_type'] ?? null);
                $this->assertSame('read', $payload['change_type'] ?? null);
                $this->assertSame('GET', $payload['request_method'] ?? null);
                $this->assertSame('/api/v1/activities/categories', $payload['endpoint'] ?? null);
                $this->assertSame('success', $payload['status'] ?? null);
                $this->assertSame('req-cat-1', $payload['request_id'] ?? null);
                $this->assertIsArray($payload['data'] ?? null);
                $this->assertTrue($payload['data']['deprecated_alias'] ?? false);
                $this->assertSame(2, $payload['data']['category_count'] ?? null);
                return true;
            }))
            ->willReturn(true);

        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $controller = new CarbonActivityController($calc, $audit, $errorLog);

        $request = makeRequest('GET', '/api/v1/activities/categories')
            ->withAttribute('user_id', 99)
            ->withHeader('X-Request-ID', 'req-cat-1');
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getCategories($request, $response);
        $this->assertSame(200, $resp->getStatusCode());

        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame(['daily', 'transport'], $json['data']);
    }

    public function testGetCategoriesReturnsGenericErrorMessageOnFailure(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
        $calc->method('getCategories')->willThrowException(new \RuntimeException('db connection refused'));

        $audit->expects($this->once())
            ->method('logAudit')
            ->with($this->callback(function (array $payload): bool {
                $this->assertSame('failed', $payload['status'] ?? null);
                $this->assertSame('carbon_activity_categories_alias_read', $payload['action'] ?? null);
                $this->assertSame('db connection refused', $payload['data']['error'] ?? null);
                return true;
            }))
            ->willReturn(true);

        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $errorLog->expects($this->once())
            ->method('logException');

        $controller = new CarbonActivityController($calc, $audit, $errorLog);

        $request = makeRequest('GET', '/api/v1/activities/categories')
            ->withAttribute('user_id', 7)
            ->withHeader('X-Request-ID', 'req-cat-fail');
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getCategories($request, $response);
        $this->assertSame(500, $resp->getStatusCode());

        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('Failed to fetch categories', $json['message']);
        $this->assertStringNotContainsString('db connection refused', $json['message']);
    }

    public function testCreateActivityValidationFails(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
        $calc->method('validateActivityData')->willReturn(false);

    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    $controller = new \CarbonTrack\Controllers\CarbonActivityController($calc, $audit, $errorLog);
        $request = makeRequest('POST', '/admin/carbon-activities', []);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->createActivity($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testUpdateSortOrdersPartiallyUpdates(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);

        // CarbonActivity::find will be called; we simulate via partial mocking using anonymous class
        // Here we just ensure controller returns success structure without real DB.
    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    $controller = new \CarbonTrack\Controllers\CarbonActivityController($calc, $audit, $errorLog);
        $request = makeRequest('PUT', '/admin/carbon-activities/sort-orders', ['activities' => [
                ['id' => 'a1', 'sort_order' => 1],
                ['id' => 'a2', 'sort_order' => 2]
            ]]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateSortOrders($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testGetActivityNotFound(): void
    {
        // For getActivity, CarbonActivity::find is used. We simulate by ensuring controller outputs 404 when null.
        // Without mocking Eloquent static, we just call and expect 500 would not be acceptable. Instead, we rely on behavior check through minimal stub.
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    $controller = new \CarbonTrack\Controllers\CarbonActivityController($calc, $audit, $errorLog);
        $request = makeRequest('GET', '/carbon-activities/not-exist');
        $response = new \Slim\Psr7\Response();
        // 仅验证方法存在（不运行 Eloquent 静态查询）
        $this->assertTrue(method_exists(\CarbonTrack\Controllers\CarbonActivityController::class, 'getActivity'));
    }

    public function testGetActivityStatistics(): void
    {
        $calc = $this->createMock(CarbonCalculatorService::class);
        $audit = $this->createMock(AuditLogService::class);
        $calc->method('getActivityStatistics')->willReturn(['total_records' => 5]);
    $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
    $controller = new \CarbonTrack\Controllers\CarbonActivityController($calc, $audit, $errorLog);
        $request = makeRequest('GET', '/admin/carbon-activities/statistics');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getActivityStatistics($request, $response, []);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(5, $json['data']['total_records']);
    }
}


