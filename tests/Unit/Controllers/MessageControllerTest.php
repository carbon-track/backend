<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Models\Message;

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

    public function testBroadcastRequiresAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 5, 'is_admin' => false]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', ['title' => 'Hello', 'content' => 'World']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testBroadcastValidatesPriority(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 6, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', [
            'title' => 'Test',
            'content' => 'Payload',
            'priority' => 'unknown-level'
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);
        $this->assertEquals(422, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertSame('Invalid priority value', $json['error']);
    }

    public function testBroadcastSendsMessagesAndReportsInvalidIds(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 42, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn(['1', '3']);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('WHERE deleted_at IS NULL AND id IN'))
            ->willReturn($statement);

        $svc->expects($this->exactly(2))
            ->method('sendSystemMessage')
            ->withConsecutive(
                [1, 'Announcement', 'Broadcast body', Message::TYPE_SYSTEM, 'high'],
                [3, 'Announcement', 'Broadcast body', Message::TYPE_SYSTEM, 'high']
            )
            ->willReturnOnConsecutiveCalls(
                $this->createMock(Message::class),
                $this->createMock(Message::class)
            );

        $audit->expects($this->once())
            ->method('log')
            ->with(
                42,
                'system_message_broadcast',
                'messages',
                null,
                $this->callback(function (array $payload): bool {
                    $this->assertSame('Announcement', $payload['title']);
                    $this->assertSame('Broadcast body', $payload['content']);
                    $this->assertSame('high', $payload['priority']);
                    $this->assertSame('custom', $payload['scope']);
                    $this->assertSame(2, $payload['sent_count']);
                    $this->assertSame(2, $payload['target_count']);
                    $this->assertSame([2], $payload['invalid_user_ids']);
                    $this->assertSame([], $payload['failed_user_ids']);
                    return true;
                })
            );

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', [
            'title' => 'Announcement',
            'content' => 'Broadcast body',
            'priority' => 'high',
            'target_users' => [1, 2, 3]
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame(2, $json['sent_count']);
        $this->assertSame(2, $json['total_targets']);
        $this->assertSame([2], $json['invalid_user_ids']);
        $this->assertSame('custom', $json['scope']);
        $this->assertSame('high', $json['priority']);
    }

    public function testGetBroadcastHistoryReturnsAggregatedData(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 99, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(1);

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->method('bindValue')->willReturn(true);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            [
                'id' => 501,
                'user_id' => 42,
                'data' => json_encode([
                    'title' => 'Hello world',
                    'content' => 'Broadcast content',
                    'priority' => 'high',
                    'scope' => 'custom',
                    'target_count' => 2,
                    'sent_count' => 2,
                    'invalid_user_ids' => json_encode([7]),
                    'failed_user_ids' => json_encode([]),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => '2025-09-22 10:00:00',
            ]
        ]);

        $actorStmt = $this->createMock(\PDOStatement::class);
        $actorStmt->method('bindValue')->willReturn(true);
        $actorStmt->method('execute')->willReturn(true);
        $actorStmt->method('fetchAll')->willReturn([
            ['id' => 42, 'username' => 'AdminUser', 'email' => 'admin@example.com']
        ]);

        $recipientsStmt = $this->createMock(\PDOStatement::class);
        $recipientsStmt->method('bindValue')->willReturn(true);
        $recipientsStmt->method('execute')->willReturn(true);
        $recipientsStmt->method('fetchAll')->willReturn([
            ['id' => 900, 'receiver_id' => 1, 'is_read' => 1, 'username' => 'Alice'],
            ['id' => 901, 'receiver_id' => 2, 'is_read' => 0, 'username' => 'Bob'],
        ]);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($countStmt, $listStmt, $actorStmt, $recipientsStmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/admin/messages/broadcasts');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getBroadcastHistory($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);
        $item = $json['data'][0];
        $this->assertSame('Hello world', $item['title']);
        $this->assertSame(1, $item['read_count']);
        $this->assertSame(1, $item['unread_count']);
        $this->assertSame([7], $item['invalid_user_ids']);
        $this->assertSame('AdminUser', $item['actor_username']);
    }
}



