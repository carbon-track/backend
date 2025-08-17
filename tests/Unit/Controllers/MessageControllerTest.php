<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;

class MessageControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(MessageController::class));
    }

    public function testGetUserMessagesReturnsPaged(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetch')->willReturn(['total' => 2]);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            ['id'=>1,'title'=>'t1','content'=>'c1','read_at'=>null],
            ['id'=>2,'title'=>'t2','content'=>'c2','read_at'=>'2025-01-01']
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserMessages($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(2, $json['pagination']['total']);
        $this->assertFalse($json['data'][0]['is_read']);
    }

    public function testGetMessageDetailMarksRead(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9]);

        // select message
        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn([
            'id' => 100, 'is_read' => false
        ]);
        // update read_at
        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);
        
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($select, $update);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages/100');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getMessageDetail($request, $response, ['id'=>100]);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(100, $json['data']['id']);
    }

    public function testGetUnreadCount(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 3]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'total_unread'=>7,'urgent_unread'=>1,'high_unread'=>2,'system_unread'=>3,'notification_unread'=>4
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages/unread-count');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUnreadCount($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(7, $json['data']['total_unread']);
    }

    public function testMarkAllAsReadMarksWhenEmptyIds(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 10]);

        $updateStmt = $this->createMock(\PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(5);
        $pdo->method('prepare')->willReturn($updateStmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/mark-all-read', []);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAllAsRead($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(5, $json['affected_rows']);
    }

    public function testMarkAllAsReadWithIds(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 10]);

        $updateStmt = $this->createMock(\PDOStatement::class);
        $updateStmt->method('execute')->willReturn(true);
        $updateStmt->method('rowCount')->willReturn(2);
        $pdo->method('prepare')->willReturn($updateStmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/mark-all-read', ['message_ids' => [1,2]]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAllAsRead($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(2, $json['affected_rows']);
    }

    public function testMarkAsReadNotOwnedReturns404(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 20]);

        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($select);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/9/read');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAsRead($request, $response, ['id' => 9]);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testMarkAsReadSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 21]);

        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id' => 300]);

        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($select, $update);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/300/read');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAsRead($request, $response, ['id' => 300]);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testDeleteMessageNotOwned(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 22]);

        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($select);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('DELETE', '/messages/12');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteMessage($request, $response, ['id' => 12]);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testDeleteMessageSuccess(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 23]);

        $select = $this->createMock(\PDOStatement::class);
        $select->method('execute')->willReturn(true);
        $select->method('fetch')->willReturn(['id' => 77]);

        $update = $this->createMock(\PDOStatement::class);
        $update->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($select, $update);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('DELETE', '/messages/77');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteMessage($request, $response, ['id' => 77]);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testGetMessageStatsAggregates(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 30]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['type' => 'system', 'priority' => 'high', 'count' => 2, 'unread_count' => 1],
            ['type' => 'notification', 'priority' => 'low', 'count' => 3, 'unread_count' => 0],
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages/stats');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getMessageStats($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(5, $json['data']['by_type']['system']['total'] + $json['data']['by_type']['notification']['total']);
    }
}


