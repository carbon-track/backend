<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

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
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        // count statement
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total' => 1]);

        // list statement
        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
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

        $tagStmt = $this->createMock(\PDOStatement::class);
        $tagStmt->method('execute')->willReturn(true);
        $tagStmt->method('fetchAll')->willReturn([
            ['product_id' => 1, 'id' => 7, 'name' => 'Popular', 'slug' => 'popular']
        ]);

        // prepare returns count then list then tags
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt, $tagStmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);

        $request = makeRequest('GET', '/products');
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getProducts($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total']);
        $this->assertEquals('Eco Bottle', $json['data']['products'][0]['name']);
        $this->assertEquals(['/a.png'], $json['data']['products'][0]['images']);
        $this->assertTrue($json['data']['products'][0]['is_available']);
        $this->assertEquals('Popular', $json['data']['products'][0]['tags'][0]['name']);
    }

    public function testGetProductDetail(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id'=>1,'name'=>'Eco Bottle','images'=>json_encode(['/a.png']),'stock'=>5,'points_required'=>100
        ]);
        $tagStmt = $this->createMock(\PDOStatement::class);
        $tagStmt->method('execute')->willReturn(true);
        $tagStmt->method('fetchAll')->willReturn([
            ['product_id' => 1, 'id' => 3, 'name' => 'Eco', 'slug' => 'eco']
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmt, $tagStmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
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
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'Eco', 'slug' => 'eco'],
            ['id' => 2, 'name' => 'Campus', 'slug' => 'campus'],
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/tags', null, ['search' => 'eco']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->searchProductTags($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(2, $json['data']['tags']);
        $this->assertEquals('eco', $json['data']['tags'][0]['slug']);
    }

    public function testExchangeProductInsufficientStock(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>1000]);

        // product select FOR UPDATE
        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>0,'points_required'=>50]);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('prepare')->willReturn($select);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('POST', '/exchange', ['product_id'=>2, 'quantity'=>1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->exchangeProduct($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertEquals('Insufficient stock', $data['error']);
    }

    public function testExchangeProductInsufficientPoints(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>10]);

        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>10,'points_required'=>50]);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('prepare')->willReturn($select);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('POST', '/exchange', ['product_id'=>2, 'quantity'=>1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->exchangeProduct($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertEquals('Insufficient points', $data['error']);
    }

    public function testExchangeProductSuccessFlow(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $messageService->expects($this->exactly(2))->method('sendMessage');
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1,'username'=>'u','points'=>1000]);

        // product select
        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id'=>2,'name'=>'Gift','status'=>'active','stock'=>10,'points_required'=>50]);
        // update user points
        $updateUser = $this->createMock(\PDOStatement::class);
        $updateUser->method('execute')->willReturn(true);
        // update stock
        $updateStock = $this->createMock(\PDOStatement::class);
        $updateStock->method('execute')->willReturn(true);
        // insert exchange record
        $insertExchange = $this->createMock(\PDOStatement::class);
        $insertExchange->method('execute')->willReturn(true);
        // insert points transaction
        $insertTxn = $this->createMock(\PDOStatement::class);
        $insertTxn->method('execute')->willReturn(true);
        // select admins to notify
        $selectAdmins = $this->createMock(\PDOStatement::class);
        $selectAdmins->method('execute')->willReturn(true);
        $selectAdmins->method('fetchAll')->willReturn([['id'=>9]]);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $select,
            $updateUser,
            $updateStock,
            $insertExchange,
            $insertTxn,
            $selectAdmins
        );

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
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
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['category' => '生活', 'product_count' => 5],
            ['category' => '出行', 'product_count' => 3]
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/products/categories');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getCategories($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $data = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']);
    }

    public function testGetExchangeRecordsRequiresAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>1]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/admin/exchanges');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecords($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testGetExchangeRecordsSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>'e1','user_id'=>1,'status'=>'pending']
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/admin/exchanges');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecords($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['pagination']['total']);
    }

    public function testUpdateExchangeStatusInvalid(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('PUT', '/admin/exchanges/e1/status', ['status' => 'unknown']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateExchangeStatus($request, $response, ['id' => 'e1']);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testUpdateExchangeStatusSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $messageService->expects($this->once())->method('sendMessage');
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);
        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id'=>'e1','user_id'=>1,'product_name'=>'Gift','quantity'=>1]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($update, $select);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('PUT', '/admin/exchanges/e1/status', ['status' => 'shipped', 'tracking_number' => 'T123']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateExchangeStatus($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testGetUserExchangesSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>'e1','current_product_images'=>null]
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
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
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>9]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['id'=>'e1','user_id'=>1,'product_name'=>'Gift']);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/admin/exchanges/e1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeRecordDetail($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('e1', $json['data']['id']);
    }

    public function testGetExchangeTransactionsAliasSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total'=>1]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>'e1','current_product_images'=>null]
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
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
        $pdo = $this->createMock(\PDO::class);
        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id'=>5]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['id'=>'e1','user_id'=>5,'product_name'=>'Gift']);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new ProductController($pdo, $messageService, $audit, $auth);
        $request = makeRequest('GET', '/exchange/transactions/e1');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getExchangeTransaction($request, $response, ['id' => 'e1']);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('e1', $json['data']['id']);
    }
}


