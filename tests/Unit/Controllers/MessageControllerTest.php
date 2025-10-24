<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\EmailService;
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
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school TEXT,
            school_id INTEGER,
            location TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');

        $pdo->exec('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $pdo->exec('CREATE TABLE message_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT,
            audit_log_id INTEGER,
            system_log_id INTEGER,
            error_log_ids TEXT,
            title TEXT,
            content TEXT,
            priority TEXT,
            scope TEXT,
            target_count INTEGER,
            sent_count INTEGER,
            invalid_user_ids TEXT,
            failed_user_ids TEXT,
            message_ids_snapshot TEXT,
            message_map_snapshot TEXT,
            message_id_count INTEGER,
            content_hash TEXT,
            email_delivery_snapshot TEXT,
            filters_snapshot TEXT,
            meta TEXT,
            created_by INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        $insertUser = $pdo->prepare('INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)');
        $insertUser->execute([1, 'User One', 'one@example.com', 0, 'active', null]);
        $insertUser->execute([3, 'User Three', 'three@example.com', 0, 'active', null]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 42, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

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

        $svc->expects($this->once())
            ->method('queueBroadcastEmail')
            ->willReturn(['error' => 'Email service unavailable']);

        $audit->expects($this->once())->method('log');

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
        $this->assertFalse($json['email_delivery']['triggered']);
        $this->assertSame('failed', $json['email_delivery']['status']);
        $this->assertContains('Email service unavailable', $json['email_delivery']['errors']);
    }


    public function testHighPriorityBroadcastTriggersEmailBcc(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school TEXT,
            school_id INTEGER,
            location TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');

        $pdo->exec('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $pdo->exec('CREATE TABLE message_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT,
            audit_log_id INTEGER,
            system_log_id INTEGER,
            error_log_ids TEXT,
            title TEXT,
            content TEXT,
            priority TEXT,
            scope TEXT,
            target_count INTEGER,
            sent_count INTEGER,
            invalid_user_ids TEXT,
            failed_user_ids TEXT,
            message_ids_snapshot TEXT,
            message_map_snapshot TEXT,
            message_id_count INTEGER,
            content_hash TEXT,
            email_delivery_snapshot TEXT,
            filters_snapshot TEXT,
            meta TEXT,
            created_by INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->prepare('INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)')
            ->execute([5, 'User Five', 'user@example.com', 0, 'active', null]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 7, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $svc->expects($this->once())
            ->method('sendSystemMessage')
            ->with(5, 'Alert', 'System high priority', Message::TYPE_SYSTEM, 'urgent')
            ->willReturn($this->createMock(Message::class));

        $svc->expects($this->once())
            ->method('queueBroadcastEmail')
            ->willReturn(['queued' => true]);

        $audit->expects($this->once())->method('log');

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', [
            'title' => 'Alert',
            'content' => 'System high priority',
            'priority' => 'urgent',
            'target_users' => [5]
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);

        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['email_delivery']['triggered']);
        $this->assertSame(1, $json['email_delivery']['attempted_recipients']);
        $this->assertSame(0, $json['email_delivery']['successful_chunks']);
        $this->assertSame([], $json['email_delivery']['failed_recipient_ids']);
        $this->assertSame('queued', $json['email_delivery']['status']);
        $this->assertSame([], $json['email_delivery']['errors']);
    }


    public function testSearchBroadcastRecipientsReturnsData(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('bindValue')->willReturn(true);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 10, 'username' => 'Alice', 'email' => 'alice@example.com', 'school' => 'Green', 'school_id' => 1, 'location' => 'City', 'is_admin' => 0, 'status' => 'active'],
            ['id' => 11, 'username' => 'Bob', 'email' => 'bob@example.com', 'school' => 'Green', 'school_id' => 1, 'location' => 'City', 'is_admin' => 0, 'status' => 'active'],
        ]);

        $pdo->method('prepare')->willReturn($stmt);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('GET', '/admin/messages/broadcast/recipients', null, ['search' => 'example', 'limit' => 1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->searchBroadcastRecipients($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);
        $this->assertTrue($json['pagination']['has_more']);
        $this->assertSame(1, $json['pagination']['page']);
    }

    public function testGetBroadcastHistoryReturnsAggregatedData(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE message_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT,
            audit_log_id INTEGER,
            system_log_id INTEGER,
            error_log_ids TEXT,
            title TEXT,
            content TEXT,
            priority TEXT,
            scope TEXT,
            target_count INTEGER,
            sent_count INTEGER,
            invalid_user_ids TEXT,
            failed_user_ids TEXT,
            message_ids_snapshot TEXT,
            message_map_snapshot TEXT,
            message_id_count INTEGER,
            content_hash TEXT,
            email_delivery_snapshot TEXT,
            filters_snapshot TEXT,
            meta TEXT,
            created_by INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec('CREATE TABLE messages (
            id INTEGER PRIMARY KEY,
            receiver_id INTEGER,
            title TEXT,
            content TEXT,
            is_read INTEGER,
            created_at TEXT,
            deleted_at TEXT
        )');

        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT
        )');

        $title = 'Hello world';
        $content = 'Broadcast content';
        $createdAt = '2025-09-22 10:00:00';
        $contentHash = hash('sha256', $title . '||' . $content);
        $emailSnapshot = [
            'triggered' => true,
            'attempted_recipients' => 2,
            'successful_chunks' => 1,
            'failed_chunks' => 0,
            'failed_recipient_ids' => [],
            'missing_email_user_ids' => [7],
            'status' => 'sent',
            'errors' => [],
        ];

        $insertBroadcast = $pdo->prepare('INSERT INTO message_broadcasts (request_id, audit_log_id, system_log_id, error_log_ids, title, content, priority, scope, target_count, sent_count, invalid_user_ids, failed_user_ids, message_ids_snapshot, message_map_snapshot, message_id_count, content_hash, email_delivery_snapshot, filters_snapshot, meta, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insertBroadcast->execute([
            'abc-123',
            321,
            654,
            json_encode([555]),
            $title,
            $content,
            'high',
            'custom',
            2,
            2,
            json_encode([7]),
            json_encode([]),
            json_encode([900, 901]),
            json_encode(['1' => 900, '2' => 901]),
            2,
            $contentHash,
            json_encode($emailSnapshot),
            json_encode(['scope' => 'custom']),
            null,
            42,
            $createdAt,
            $createdAt,
        ]);

        $msgStmt = $pdo->prepare('INSERT INTO messages (id, receiver_id, title, content, is_read, created_at, deleted_at) VALUES (?,?,?,?,?,?,?)');
        $msgStmt->execute([900, 1, $title, $content, 1, $createdAt, null]);
        $msgStmt->execute([901, 2, $title, $content, 0, $createdAt, null]);

        $userStmt = $pdo->prepare('INSERT INTO users (id, username, email) VALUES (?,?,?)');
        $userStmt->execute([42, 'AdminUser', 'admin@example.com']);
        $userStmt->execute([1, 'Alice', 'alice@example.com']);
        $userStmt->execute([2, 'Bob', 'bob@example.com']);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 99, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

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
        $this->assertSame('abc-123', $item['request_id']);
        $this->assertSame(321, $item['audit_log_id']);
        $this->assertSame(654, $item['system_log_id']);
        $this->assertSame([555], $item['error_log_ids']);
        $this->assertSame([900, 901], $item['message_ids']);
        $this->assertSame('sent', $item['email_delivery']['status']);
        $this->assertSame([7], $item['email_delivery']['missing_email_user_ids']);
        $this->assertSame([], $item['email_delivery']['errors']);
    }

    public function testFlushBroadcastQueueMarksQueuedAsSentWithoutForce(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', fn(): string => date('Y-m-d H:i:s'));

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, receiver_id INTEGER, title TEXT, content TEXT, is_read INTEGER DEFAULT 0, created_at TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE message_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            content TEXT,
            priority TEXT,
            created_at TEXT,
            email_delivery_snapshot TEXT,
            message_ids_snapshot TEXT,
            content_hash TEXT,
            updated_at TEXT
        )');

        $title = 'Queue Notice';
        $content = 'Please review the latest announcement.';
        $priority = 'urgent';
        $createdAt = '2025-01-01 10:00:00';
        $hash = hash('sha256', $title . '||' . $content);

        $pdo->prepare('INSERT INTO users (id, username, email) VALUES (?,?,?)')
            ->execute([10, 'QueueUser', 'queue@example.com']);

        $pdo->prepare('INSERT INTO messages (id, receiver_id, title, content, is_read, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([501, 10, $title, $content, 0, $createdAt]);

        $snapshot = json_encode([
            'triggered' => true,
            'attempted_recipients' => 1,
            'successful_chunks' => 0,
            'failed_chunks' => 0,
            'failed_recipient_ids' => [],
            'missing_email_user_ids' => [],
            'status' => 'queued',
            'errors' => [],
            'completed_at' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->prepare('INSERT INTO message_broadcasts (id, title, content, priority, created_at, email_delivery_snapshot, message_ids_snapshot, content_hash) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([1, $title, $content, $priority, $createdAt, $snapshot, json_encode([501]), $hash]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 88, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = new MessageController($pdo, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcasts/flush', ['limit' => 5]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->flushBroadcastEmailQueue($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame(1, $json['count']);
        $this->assertSame('sent', $json['processed'][0]['status']);

        $snapshotRow = $pdo->query('SELECT email_delivery_snapshot FROM message_broadcasts WHERE id = 1')->fetchColumn();
        $this->assertNotFalse($snapshotRow);
        $decoded = json_decode((string)$snapshotRow, true);
        $this->assertSame('sent', $decoded['status']);
        $this->assertNotEmpty($decoded['completed_at']);
    }

    public function testFlushBroadcastQueueForceSendFailure(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', fn(): string => date('Y-m-d H:i:s'));

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, receiver_id INTEGER, title TEXT, content TEXT, is_read INTEGER DEFAULT 0, created_at TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE message_broadcasts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT,
            content TEXT,
            priority TEXT,
            created_at TEXT,
            email_delivery_snapshot TEXT,
            message_ids_snapshot TEXT,
            content_hash TEXT,
            updated_at TEXT
        )');

        $title = 'Force Send';
        $content = 'Force send announcement';
        $priority = 'high';
        $createdAt = '2025-02-02 09:00:00';
        $hash = hash('sha256', $title . '||' . $content);

        $pdo->prepare('INSERT INTO users (id, username, email) VALUES (?,?,?)')
            ->execute([20, 'ForceUser', 'force@example.com']);

        $pdo->prepare('INSERT INTO messages (id, receiver_id, title, content, is_read, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([701, 20, $title, $content, 0, $createdAt]);

        $snapshot = json_encode([
            'triggered' => true,
            'attempted_recipients' => 1,
            'successful_chunks' => 0,
            'failed_chunks' => 0,
            'failed_recipient_ids' => [],
            'missing_email_user_ids' => [],
            'status' => 'queued',
            'errors' => [],
            'completed_at' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $pdo->prepare('INSERT INTO message_broadcasts (id, title, content, priority, created_at, email_delivery_snapshot, message_ids_snapshot, content_hash) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([2, $title, $content, $priority, $createdAt, $snapshot, json_encode([701]), $hash]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 59, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendAnnouncementBroadcast')
            ->willReturn(false);
        $emailService->method('getLastError')->willReturn('mailer down');

        $controller = new MessageController($pdo, $svc, $audit, $auth, $emailService);
        $request = makeRequest('POST', '/admin/messages/broadcasts/flush', ['limit' => 5, 'force' => 1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->flushBroadcastEmailQueue($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('failed', $json['processed'][0]['status']);
        $this->assertContains('mailer down', $json['processed'][0]['errors']);

        $snapshotRow = $pdo->query('SELECT email_delivery_snapshot FROM message_broadcasts WHERE id = 2')->fetchColumn();
        $this->assertNotFalse($snapshotRow);
        $decoded = json_decode((string)$snapshotRow, true);
        $this->assertSame('failed', $decoded['status']);
        $this->assertContains('mailer down', $decoded['errors']);
    }
}



