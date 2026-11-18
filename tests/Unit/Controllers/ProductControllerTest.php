<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Support\DatabaseRows;
use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use CarbonTrack\Tests\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\ProductController;

class ProductControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(ProductController::class));
    }

    public function testGetProductsReturnsJson(): void
    {
        // Mocks
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        // count statement
        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->method('bindings')->willReturn(true);
        $countStmt->method('row')->willReturn(['total' => 1]);

        // list statement
        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([
            [
                'id' => 1,
                'name' => 'Eco Bottle',
                'description' => 'Nice',
                'images' => json_encode(['/a.png']),
                'stock' => 10,
                'points_required' => 100,
                'status' => 'active'
            ]
        ]);

        $tagStmt = $this->createMock(DatabaseQueryResponse::class);
        $tagStmt->shape = 'rows';
        $tagStmt->method('bindings')->willReturn(true);
        $tagStmt->method('rows')->willReturn([
            ['product_id' => 1, 'id' => 7, 'name' => 'Popular', 'slug' => 'popular']
        ]);

        // prepare returns count then list then tags
        $connection->method('responseFor')->willReturnOnConsecutiveCalls($countStmt, $listStmt, $tagStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);

        $request = makeRequest('GET', '/products');
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getProducts($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total']);
        $this->assertEquals('Eco Bottle', $json['data']['products'][0]['name']);
        $this->assertIsArray($json['data']['products'][0]['images']);
        $this->assertEquals('a.png', $json['data']['products'][0]['images'][0]['file_path'] ?? null);
        $this->assertTrue($json['data']['products'][0]['is_available']);
        $this->assertEquals('Popular', $json['data']['products'][0]['tags'][0]['name']);
    }

    public function testGetProductsUsesDistinctSearchBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $listBound = [];
        $prepareCalls = 0;

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->expects($this->once())
            ->method('bindings')
            ->with([
                'search_name' => '%eco%',
                'search_description' => '%eco%',
            ])
            ->willReturn(true);
        $countStmt->expects($this->once())->method('row')->willReturn(['total' => 0]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->expects($this->exactly(4))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$listBound) {
                $listBound[$key] = [$value, $type];
                return true;
            });
        $listStmt->expects($this->once())->method('bindings')->willReturn(true);
        $listStmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $countStmt, $listStmt) {
                $prepareCalls++;
                $this->assertStringContainsString('p.name LIKE :search_name', $sql);
                $this->assertStringContainsString('p.description LIKE :search_description', $sql);

                return $prepareCalls === 1 ? $countStmt : $listStmt;
            });

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products', null, ['search' => 'eco']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getProducts($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());

        $this->assertSame('%eco%', $listBound['search_name'][0] ?? null);
        $this->assertSame('%eco%', $listBound['search_description'][0] ?? null);
        $this->assertSame(20, $listBound['limit'][0] ?? null);
        $this->assertSame('int', $listBound['limit'][1] ?? null);
        $this->assertSame(0, $listBound['offset'][0] ?? null);
        $this->assertSame('int', $listBound['offset'][1] ?? null);
    }

    public function testGetProductDetail(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn([
            'id'=>1,'name'=>'Eco Bottle','images'=>json_encode(['/a.png']),'stock'=>5,'points_required'=>100
        ]);
        $tagStmt = $this->createMock(DatabaseQueryResponse::class);
        $tagStmt->shape = 'rows';
        $tagStmt->method('bindings')->willReturn(true);
        $tagStmt->method('rows')->willReturn([
            ['product_id' => 1, 'id' => 3, 'name' => 'Eco', 'slug' => 'eco']
        ]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($stmt, $tagStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getProductDetail($request, $response, ['id'=>1]);
        $this->assertEquals(200, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Eco Bottle', $data['data']['name']);
        $this->assertEquals('Eco', $data['data']['tags'][0]['name']);
    }

    public function testSearchProductTags(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->checkParameters = true;
        $stmt->method('parameter')->willReturn(true);
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id' => 1, 'name' => 'Eco', 'slug' => 'eco'],
            ['id' => 2, 'name' => 'Campus', 'slug' => 'campus'],
        ]);
        $connection->method('responseFor')->willReturn($stmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/tags', null, ['search' => 'eco']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->searchProductTags($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(2, $json['data']['tags']);
        $this->assertEquals('eco', $json['data']['tags'][0]['slug']);
    }

    public function testSearchProductTagsUsesDistinctSearchBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $bound = [];

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->checkParameters = true;
        $stmt->expects($this->exactly(3))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$bound) {
                $bound[$key] = [$value, $type];
                return true;
            });
        $stmt->expects($this->once())->method('bindings')->willReturn(true);
        $stmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->once())
            ->method('responseFor')
            ->with($this->callback(static function (string $sql): bool {
                return str_contains($sql, 'name LIKE :search_name')
                    && str_contains($sql, 'slug LIKE :search_slug')
                    && !str_contains($sql, 'slug LIKE :search ');
            }))
            ->willReturn($stmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/tags', null, ['search' => 'eco']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->searchProductTags($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertSame('%eco%', $bound['search_name'][0] ?? null);
        $this->assertSame('%eco%', $bound['search_slug'][0] ?? null);
        $this->assertSame(20, $bound['limit'][0] ?? null);
        $this->assertSame('int', $bound['limit'][1] ?? null);
    }

    public function testExchangeProductInsufficientStock(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'responseFor', 'rollBack'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>1000]);

        // product select FOR UPDATE
        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>0,'points_required'=>50]);
        $connection->method('beginTransaction')->willReturn(true);
        $connection->method('rollBack')->willReturn(true);
        $connection->method('responseFor')->willReturn($select);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('POST', '/exchange', ['product_id'=>2, 'quantity'=>1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->exchangeProduct($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertEquals('Insufficient stock', $data['error']);
    }

    public function testExchangeProductInsufficientPoints(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'responseFor', 'rollBack'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>10]);

        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>10,'points_required'=>50]);
        $connection->method('beginTransaction')->willReturn(true);
        $connection->method('rollBack')->willReturn(true);
        $connection->method('responseFor')->willReturn($select);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('POST', '/exchange', ['product_id'=>2, 'quantity'=>1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->exchangeProduct($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertEquals('Insufficient points', $data['error']);
    }

    public function testExchangeProductSuccessFlow(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $messageService->expects($this->once())->method('sendMessage');
        $messageService->expects($this->once())
            ->method('sendExchangeConfirmationEmailToUser')
            ->with(
                $this->equalTo(1),
                $this->equalTo('Gift'),
                $this->equalTo(2),
                $this->equalTo(100.0),
                $this->equalTo(null),
                $this->equalTo('u')
            );
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>1000]);

        // product select
        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>10,'points_required'=>50]);
        // update user points
        $updateUser = $this->createMock(DatabaseQueryResponse::class);
        $updateUser->method('bindings')->willReturn(true);
        // update stock
        $updateStock = $this->createMock(DatabaseQueryResponse::class);
        $updateStock->method('bindings')->willReturn(true);
        // insert exchange record
        $insertExchange = $this->createMock(DatabaseQueryResponse::class);
        $insertExchange->method('bindings')->willReturn(true);
        // insert points transaction
        $insertTxn = $this->createMock(DatabaseQueryResponse::class);
        $insertTxn->method('bindings')->willReturn(true);
        // select admins to notify
        $selectAdmins = $this->createMock(DatabaseQueryResponse::class);
        $selectAdmins->shape = 'rows';
        $selectAdmins->method('bindings')->willReturn(true);
        $selectAdmins->method('rows')->willReturn([['id'=>9]]);

        $connection->method('beginTransaction')->willReturn(true);
        $connection->method('commit')->willReturn(true);
        $connection->method('responseFor')->willReturnOnConsecutiveCalls(
            $select,
            $updateUser,
            $updateStock,
            $insertExchange,
            $insertTxn,
            $selectAdmins,
            $selectAdmins,
            $selectAdmins
        );

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('POST', '/exchange', ['product_id'=>2, 'quantity'=>2]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->exchangeProduct($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(100, $json['points_used']);
    }

    public function testGetCategories(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $categoryStmt = $this->createMock(DatabaseQueryResponse::class);
        $categoryStmt->shape = 'rows';
        $categoryStmt->checkParameters = true;
        $categoryStmt->method('bindings')->willReturn(true);
        $categoryStmt->method('parameter')->willReturn(true);
        $categoryStmt->method('rows')->willReturn([
            ['id' => 1, 'name' => 'Eco Living', 'slug' => 'eco-living', 'product_count' => 5]
        ]);

        $fallbackStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackStmt->shape = 'rows';
        $fallbackStmt->checkParameters = true;
        $fallbackStmt->method('bindings')->willReturn(true);
        $fallbackStmt->method('parameter')->willReturn(true);
        $fallbackStmt->method('rows')->willReturn([
            ['name' => '社区种子', 'slug' => null, 'product_count' => 3]
        ]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($categoryStmt, $fallbackStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/categories', null, ['limit' => 10]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getCategories($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('categories', $data['data']);
        $this->assertCount(2, $data['data']['categories']);
        $names = array_column($data['data']['categories'], 'name');
        $this->assertContains('Eco Living', $names);
        $this->assertContains('社区种子', $names);
    }

    public function testGetExchangeRecordsRequiresAdmin(): void
    {
        $connection = new DatabaseConnectionStub();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->never())->method('logAdminRead');
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/admin/exchanges');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecords($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testGetExchangeRecordsSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->method('bindings')->willReturn(true);
        $countStmt->method('row')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([
            ['id'=>'e1','user_id'=>1,'status'=>'pending','email'=>'buyer@example.test']
        ]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($this->exchangeUserColumn(), $countStmt, $listStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/api/v1/admin/exchanges', null, [
            'page' => 0, 'limit' => 5, 'search' => 'buyer@example.test',
        ])->withAttribute('request_id', 'exchange-list-fixture');
        $audit->expects($this->once())->method('logAdminRead')->with(
            $request,
            'admin_exchange_records_viewed',
            9,
            'point_exchanges',
            ['page' => 1, 'limit' => 10, 'exchange_ids' => ['e1']]
        )->willReturn(true);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecords($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
        $this->assertSame('buyer@example.test', $json['data'][0]['user_email']);
    }

    public function testGetExchangeRecordsSearchUsesDistinctBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9]);
        $auth->method('isAdminUser')->willReturn(true);

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->expects($this->once())
            ->method('bindings')
            ->with($this->callback(function ($params) {
                foreach (['exchange_search_0', 'exchange_search_1', 'exchange_search_2', 'exchange_search_3', 'exchange_search_4', 'exchange_search_5'] as $key) {
                    if (!array_key_exists($key, $params)) {
                        return false;
                    }
                }
                return !array_key_exists('search', $params);
            }))
            ->willReturn(true);
        $countStmt->method('row')->willReturn(['total' => 0]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($this->exchangeUserColumn(), $countStmt, $listStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/admin/exchanges', null, ['search' => '599ef56d-13ad-47ee-8f91-beb85d2d3b67']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecords($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
    }

    public function testUpdateExchangeStatusInvalid(): void
    {
        $connection = new DatabaseConnectionStub();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('PUT', '/admin/exchanges/e1/status', ['status' => 'unknown']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateExchangeStatus($request, $response, ['id' => 'e1']);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testUpdateExchangeStatusSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $messageService->expects($this->once())->method('sendMessage');
        $messageService->expects($this->once())
            ->method('sendExchangeStatusUpdateEmailToUser')
            ->with(
                $this->equalTo(1),
                $this->equalTo('Gift'),
                $this->equalTo('shipped'),
                $this->equalTo('T123'),
                $this->equalTo(null),
                $this->equalTo(null),
                $this->equalTo(null)
            );
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $update = $this->createMock(DatabaseQueryResponse::class);
        $update->method('bindings')->willReturn(true);
        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id'=>'e1','user_id'=>1,'product_name'=>'Gift','quantity'=>1]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($update, $this->exchangeUserColumn(), $select);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('PUT', '/admin/exchanges/e1/status', ['status' => 'shipped', 'tracking_number' => 'T123']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateExchangeStatus($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testGetUserExchangesSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->method('bindings')->willReturn(true);
        $countStmt->method('row')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([
            ['id'=>'e1','current_product_images'=>null]
        ]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($this->exchangeUserColumn(), $countStmt, $listStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/exchange/transactions');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserExchanges($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
    }

    public function testGetExchangeRecordDetailSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn(['id'=>'e1','user_id'=>1,'product_name'=>'Gift']);
        $connection->method('responseFor')->willReturn($stmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/api/v1/admin/exchanges/e1')
            ->withAttribute('request_id', 'exchange-detail-fixture');
        $audit->expects($this->once())->method('logAdminRead')->with(
            $request,
            'admin_exchange_record_viewed',
            9,
            'point_exchanges',
            ['exchange_id' => 'e1']
        )->willReturn(true);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecordDetail($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('e1', $json['data']['id']);
    }

    public function testGetExchangeTransactionsAliasSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->method('bindings')->willReturn(true);
        $countStmt->method('row')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([
            ['id'=>'e1','current_product_images'=>null]
        ]);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($this->exchangeUserColumn(), $countStmt, $listStmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/exchange/transactions');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeTransactions($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
    }

    public function testGetExchangeTransactionDetailSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn(['id'=>'e1','user_id'=>5,'product_name'=>'Gift']);
        $connection->method('responseFor')->willReturn($stmt);

        $controller = new ProductController($connection, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/exchange/transactions/e1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeTransaction($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('e1', $json['data']['id']);
    }

    public function testCreateProductCreatesCategoryAndPersistsSlug(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $connection->unprepared('CREATE TABLE product_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, description TEXT)');
        $connection->unprepared('CREATE TABLE product_tag_map (product_id INTEGER, tag_id INTEGER, created_at TEXT)');
        $connection->unprepared('CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT,
            category_slug TEXT,
            points_required INTEGER NOT NULL,
            description TEXT,
            image_path TEXT,
            images TEXT,
            stock INTEGER NOT NULL,
            status TEXT,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 99]);
        $auth->method('isAdminUser')->willReturn(true);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $capturedException = null;
        $errorLog->expects($this->any())
            ->method('logException')
            ->willReturnCallback(function ($exception) use (&$capturedException) {
                $capturedException = $exception;
            });

        $controller = new ProductController($connection, $messageService, $audit, $auth, $errorLog);

        $request = makeRequest('POST', '/admin/products', [
            'name' => 'Reusable Cup',
            'description' => 'Great for the office',
            'points_required' => 120,
            'stock' => 25,
            'category' => [
                'name' => 'Office Supplies',
                'slug' => 'office-supplies'
            ],
            'tags' => []
        ]);
        $response = new \Slim\Psr7\Response();

        $result = $controller->createProduct($request, $response);
        if ($result->getStatusCode() !== 201) {
            $details = $capturedException instanceof \Throwable ? $capturedException->getMessage() : 'no exception captured';
            $this->fail('Unexpected response: ' . (string) $result->getBody() . ' (reason: ' . $details . ')');
        }
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertNotEmpty($payload['id']);

        $categoryRow = DatabaseRows::one($connection->selectOne('SELECT name, slug FROM product_categories LIMIT 1'));
        $this->assertSame('Office Supplies', $categoryRow['name']);
        $this->assertSame('office-supplies', $categoryRow['slug']);

        $productRow = DatabaseRows::one($connection->selectOne('SELECT category, category_slug FROM products LIMIT 1'));
        $this->assertSame('Office Supplies', $productRow['category']);
        $this->assertSame('office-supplies', $productRow['category_slug']);
    }

    public function testGetCategoriesReturnsStructuredResponse(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $connection->unprepared('CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category TEXT,
            category_slug TEXT,
            points_required INTEGER NOT NULL,
            description TEXT,
            image_path TEXT,
            images TEXT,
            stock INTEGER NOT NULL,
            status TEXT,
            sort_order INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
        $connection->unprepared("INSERT INTO product_categories (name, slug, created_at) VALUES ('Eco Living', 'eco-living', CURRENT_TIMESTAMP)");
        $connection->unprepared("INSERT INTO products (name, category, category_slug, points_required, description, image_path, images, stock, status, sort_order, created_at) VALUES ('Bottle', 'Eco Living', 'eco-living', 100, '', '', '[]', 10, 'active', 0, CURRENT_TIMESTAMP)");
        $connection->unprepared("INSERT INTO products (name, category, category_slug, points_required, description, image_path, images, stock, status, sort_order, created_at) VALUES ('DIY Kit', '手工材料', '', 200, '', '', '[]', 5, 'active', 0, CURRENT_TIMESTAMP)");

        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $errorLog->expects($this->any())->method('logException');

        $controller = new ProductController($connection, $messageService, $audit, $auth, $errorLog);

        $request = makeRequest('GET', '/products/categories', null, ['limit' => 10]);
        $response = new \Slim\Psr7\Response();

        $result = $controller->getCategories($request, $response);
        $this->assertEquals(200, $result->getStatusCode(), 'Unexpected response: ' . (string) $result->getBody());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('categories', $payload['data']);

        $categories = $payload['data']['categories'];
        $this->assertNotEmpty($categories);

        $names = array_column($categories, 'name');
        $this->assertContains('Eco Living', $names);
        $this->assertContains('手工材料', $names);
    }

    public function testGetCategoriesUsesDistinctSearchBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $errorLog->expects($this->never())->method('logException');
        $categoryBound = [];
        $prepareCalls = 0;

        $categoryStmt = $this->createMock(DatabaseQueryResponse::class);
        $categoryStmt->shape = 'rows';
        $categoryStmt->checkParameters = true;
        $categoryStmt->expects($this->exactly(3))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$categoryBound) {
                $categoryBound[$key] = [$value, $type];
                return true;
            });
        $categoryStmt->expects($this->once())->method('bindings')->willReturn(true);
        $categoryStmt->expects($this->once())->method('rows')->willReturn([]);

        $fallbackStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackStmt->shape = 'rows';
        $fallbackStmt->checkParameters = true;
        $fallbackStmt->expects($this->once())
            ->method('parameter')
            ->with('fallback_limit', 20, 'int')
            ->willReturn(true);
        $fallbackStmt->expects($this->once())->method('bindings')->willReturn(true);
        $fallbackStmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $categoryStmt, $fallbackStmt) {
                $prepareCalls++;
                if ($prepareCalls === 1) {
                    $this->assertStringContainsString('pc.name LIKE :search_name', $sql);
                    $this->assertStringContainsString('pc.slug LIKE :search_slug', $sql);
                    $this->assertStringNotContainsString('pc.slug LIKE :search ', $sql);
                    return $categoryStmt;
                }

                $this->assertStringContainsString('LIMIT :fallback_limit', $sql);
                return $fallbackStmt;
            });

        $controller = new ProductController($connection, $messageService, $audit, $auth, $errorLog);
        $request = makeRequest('GET', '/products/categories', null, ['search' => 'eco', 'limit' => 10]);
        $response = new \Slim\Psr7\Response();

        $result = $controller->getCategories($request, $response);
        $this->assertEquals(200, $result->getStatusCode(), 'Unexpected response: ' . (string) $result->getBody());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']['categories']);
        $this->assertSame('%eco%', $categoryBound['search_name'][0] ?? null);
        $this->assertSame('%eco%', $categoryBound['search_slug'][0] ?? null);
        $this->assertSame(10, $categoryBound['limit'][0] ?? null);
        $this->assertSame('int', $categoryBound['limit'][1] ?? null);
    }


    private function exchangeUserColumn(): DatabaseQueryResponse
    {
        $column = $this->createMock(DatabaseQueryResponse::class);
        $column->shape = 'row';
        $column->method('row')->willReturn(['Field' => 'user_id']);

        return $column;
    }
}

