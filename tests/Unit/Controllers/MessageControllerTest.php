<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use Illuminate\Database\Connection;
use CarbonTrack\Support\DatabaseRows;
use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use CarbonTrack\Tests\Support\DatabaseFixture;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\UserProfileViewService;
use CarbonTrack\Models\Message;

class MessageControllerTest extends TestCase
{
    private function makeUserProfileViewService(?RegionService $regionService = null): UserProfileViewService
    {
        return new UserProfileViewService($regionService ?? new RegionService(null, null, null, null));
    }

    private function makeController(
        Connection $connection,
        MessageService $svc,
        AuditLogService $audit,
        AuthService $auth,
        ?EmailService $emailService = null,
        ?UserProfileViewService $userProfileViewService = null,
        ?ErrorLogService $errorLogService = null
    ): MessageController {
        return new MessageController(
            $connection,
            $svc,
            $audit,
            $auth,
            $userProfileViewService ?? $this->makeUserProfileViewService(),
            $emailService,
            $errorLogService
        );
    }

    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(MessageController::class));
    }

    public function testGetUserMessagesReturnsPaged(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->method('bindings')->willReturn(true);
        $countStmt->method('row')->willReturn(['total' => 2]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->method('parameter')->willReturn(true);
        $listStmt->method('bindings')->willReturn(true);
        $listStmt->method('rows')->willReturn([
            ['id'=>1,'title'=>'t1','content'=>'c1','read_at'=>null],
            ['id'=>2,'title'=>'t2','content'=>'c2','read_at'=>'2025-01-01']
        ]);

        $connection->method('responseFor')->willReturnCallback(static function (string $sql) use ($countStmt, $listStmt): ?DatabaseQueryResponse {
            if (str_starts_with($sql, 'SHOW COLUMNS')) {
                return null;
            }

            return str_contains($sql, 'COUNT(*)') ? $countStmt : $listStmt;
        });

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserMessages($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(2, $json['pagination']['total']);
        $this->assertFalse($json['data'][0]['is_read']);
    }

    public function testGetUserMessagesUsesDistinctSearchBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);
        $listBound = [];

        $countStmt = $this->createMock(DatabaseQueryResponse::class);
        $countStmt->shape = 'row';
        $countStmt->expects($this->once())
            ->method('bindings')
            ->with([
                'user_id' => 1,
                'search_title' => '%eco%',
                'search_content' => '%eco%',
            ])
            ->willReturn(true);
        $countStmt->expects($this->once())->method('row')->willReturn(['total' => 0]);

        $listStmt = $this->createMock(DatabaseQueryResponse::class);
        $listStmt->shape = 'rows';
        $listStmt->checkParameters = true;
        $listStmt->expects($this->exactly(5))
            ->method('parameter')
            ->willReturnCallback(function (string $key, $value, ?string $type = null) use (&$listBound) {
                $listBound[$key] = [$value, $type];
                return true;
            });
        $listStmt->expects($this->once())->method('bindings')->willReturn(true);
        $listStmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->exactly(3))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use ($countStmt, $listStmt) {
                if (str_starts_with($sql, 'SHOW COLUMNS')) {
                    return null;
                }

                $this->assertStringContainsString('m.title LIKE :search_title', $sql);
                $this->assertStringContainsString('m.content LIKE :search_content', $sql);
                return str_contains($sql, 'COUNT(*)') ? $countStmt : $listStmt;
            });

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('GET', '/messages', null, ['search' => 'eco']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->getUserMessages($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertSame(1, $listBound['user_id'][0] ?? null);
        $this->assertSame('%eco%', $listBound['search_title'][0] ?? null);
        $this->assertSame('%eco%', $listBound['search_content'][0] ?? null);
        $this->assertSame(20, $listBound['limit'][0] ?? null);
        $this->assertSame(0, $listBound['offset'][0] ?? null);
    }

    public function testGetMessageDetailMarksRead(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['user_id'] === 9
                && $entry['action'] === 'messages_marked_read'
                && $entry['affected_table'] === 'messages'
                && $entry['data']['scope'] === 'single'
                && $entry['data']['requested_message_ids'] === [100]
                && $entry['data']['affected_rows'] === 1
        ));
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9]);

        // select message
        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn([
            'id' => 100, 'is_read' => false
        ]);
        // update read_at
        $update = $this->createMock(DatabaseQueryResponse::class);
        $update->method('bindings')->willReturn(true);
        $update->method('affected')->willReturn(1);
        
        $connection->method('responseFor')->willReturnOnConsecutiveCalls($select, $update);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 3]);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn([
            'total_unread'=>7,'urgent_unread'=>1,'high_unread'=>2,'system_unread'=>3,'notification_unread'=>4
        ]);
        $connection->method('responseFor')->willReturn($stmt);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['user_id'] === 10
                && $entry['action'] === 'messages_marked_read'
                && $entry['affected_table'] === 'messages'
                && $entry['data']['scope'] === 'all'
                && $entry['data']['requested_message_ids'] === []
                && $entry['data']['affected_rows'] === 5
        ));
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 10]);

        $updateStmt = $this->createMock(DatabaseQueryResponse::class);
        $updateStmt->method('bindings')->willReturn(true);
        $updateStmt->method('affected')->willReturn(5);
        $connection->method('responseFor')->willReturn($updateStmt);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['user_id'] === 10
                && $entry['action'] === 'messages_marked_read'
                && $entry['affected_table'] === 'messages'
                && $entry['data']['scope'] === 'selected'
                && $entry['data']['requested_message_ids'] === [1, 2]
                && $entry['data']['affected_rows'] === 2
        ));
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 10]);

        $updateStmt = $this->createMock(DatabaseQueryResponse::class);
        $updateStmt->method('bindings')->willReturn(true);
        $updateStmt->method('affected')->willReturn(2);
        $connection->method('responseFor')->willReturn($updateStmt);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/mark-all-read', ['message_ids' => [1,2]]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAllAsRead($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(2, $json['affected_rows']);
    }

    public function testMarkSelectedMessagesAuditsOnlyChangedRows(): void
    {
        $connection = DatabaseFixture::sqlite();
        $connection->statement('CREATE TABLE messages (id INTEGER PRIMARY KEY, receiver_id INTEGER, is_read INTEGER, updated_at DATETIME, deleted_at DATETIME)');
        $connection->table('messages')->insert([
            ['id' => 1, 'receiver_id' => 10, 'is_read' => 0, 'deleted_at' => null],
            ['id' => 2, 'receiver_id' => 11, 'is_read' => 0, 'deleted_at' => null],
            ['id' => 3, 'receiver_id' => 10, 'is_read' => 0, 'deleted_at' => '2025-01-01'],
        ]);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['data']['scope'] === 'selected'
                && $entry['data']['requested_message_ids'] === [1, 2, 3]
                && $entry['data']['affected_rows'] === 1
        ));
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 10]);
        $controller = $this->makeController($connection, $this->createMock(MessageService::class), $audit, $auth);
        $request = makeRequest('PUT', '/api/v1/messages/mark-all-read', ['message_ids' => [1, 2, 3]]);

        $first = $controller->markAllAsRead($request, new \Slim\Psr7\Response());
        $retry = $controller->markAllAsRead($request, new \Slim\Psr7\Response());

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(1, json_decode((string) $first->getBody(), true)['affected_rows']);
        $this->assertSame(0, json_decode((string) $retry->getBody(), true)['affected_rows']);
        $this->assertSame([1, 0, 0], array_map('intval', $connection->table('messages')->orderBy('id')->pluck('is_read')->all()));
    }

    public function testMarkAsReadNotOwnedReturns404(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->never())->method('log');
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 20]);

        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(false);
        $connection->method('responseFor')->willReturn($select);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/9/read');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAsRead($request, $response, ['id' => 9]);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testMarkAsReadSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['user_id'] === 21
                && $entry['action'] === 'messages_marked_read'
                && $entry['affected_table'] === 'messages'
                && $entry['data']['scope'] === 'single'
                && $entry['data']['requested_message_ids'] === [300]
                && $entry['data']['affected_rows'] === 1
        ));
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 21]);

        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id' => 300]);

        $update = $this->createMock(DatabaseQueryResponse::class);
        $update->method('bindings')->willReturn(true);
        $update->method('affected')->willReturn(1);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($select, $update);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('PUT', '/messages/300/read');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->markAsRead($request, $response, ['id' => 300]);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testDeleteMessageNotOwned(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 22]);

        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(false);
        $connection->method('responseFor')->willReturn($select);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('DELETE', '/messages/12');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteMessage($request, $response, ['id' => 12]);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testDeleteMessageSuccess(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 23]);

        $select = $this->createMock(DatabaseQueryResponse::class);
        $select->shape = 'row';
        $select->method('bindings')->willReturn(true);
        $select->method('row')->willReturn(['id' => 77]);

        $update = $this->createMock(DatabaseQueryResponse::class);
        $update->method('bindings')->willReturn(true);

        $connection->method('responseFor')->willReturnOnConsecutiveCalls($select, $update);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('DELETE', '/messages/77');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteMessage($request, $response, ['id' => 77]);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
    }

    public function testBroadcastRequiresAdmin(): void
    {
        $connection = new DatabaseConnectionStub();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 5, 'is_admin' => false]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', ['title' => 'Hello', 'content' => 'World']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testBroadcastValidatesPriority(): void
    {
        $connection = new DatabaseConnectionStub();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 6, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school_id INTEGER,
            region_code TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');
        $connection->unprepared('CREATE TABLE schools (
            id INTEGER PRIMARY KEY,
            name TEXT
        )');

        $connection->unprepared('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $insertUserSql = 'INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)';
        $connection->statement($insertUserSql, [1, 'User One', 'one@example.com', 0, 'active', null]);
        $connection->statement($insertUserSql, [3, 'User Three', 'three@example.com', 0, 'active', null]);

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

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school_id INTEGER,
            region_code TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');
        $connection->unprepared('CREATE TABLE schools (
            id INTEGER PRIMARY KEY,
            name TEXT
        )');

        $connection->unprepared('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->statement('INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)', [5, 'User Five', 'user@example.com', 0, 'active', null]);

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

        $controller = $this->makeController($connection, $svc, $audit, $auth);
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

    public function testBroadcastSupportsHtmlContentFormatMetadata(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school_id INTEGER,
            region_code TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');
        $connection->unprepared('CREATE TABLE schools (
            id INTEGER PRIMARY KEY,
            name TEXT
        )');

        $connection->unprepared('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->statement('INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)', [5, 'User Five', 'user@example.com', 0, 'active', null]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 7, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $svc->expects($this->once())
            ->method('sendSystemMessage')
            ->with(
                5,
                'Alert',
                $this->callback(static function (string $content): bool {
                    return str_contains($content, '<h1>Headline</h1>')
                        && str_contains($content, '<p>Body</p>')
                        && !str_contains($content, '<script');
                }),
                Message::TYPE_SYSTEM,
                'high'
            )
            ->willReturn($this->createMock(Message::class));

        $svc->expects($this->once())
            ->method('queueBroadcastEmail')
            ->with(
                $this->anything(),
                'Alert',
                $this->callback(static fn (string $content): bool => !str_contains($content, '<script')),
                'high',
                $this->callback(static function (array $context): bool {
                    return ($context['content_format'] ?? null) === 'html'
                        && ($context['render_profile'] ?? null) === 'announcement_html_v1'
                        && ($context['render_version'] ?? null) === 1
                        && ($context['source_kind'] ?? null) === 'admin_broadcast';
                })
            )
            ->willReturn(['queued' => true, 'recipient_count' => 1]);

        $audit->expects($this->once())->method('log');

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', [
            'title' => 'Alert',
            'content' => '<h1>Headline</h1><script>alert(1)</script><p>Body</p>',
            'content_format' => 'html',
            'render_profile' => 'announcement_html_v1',
            'priority' => 'high',
            'target_users' => [5]
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('html', $json['content_format']);
        $this->assertSame('announcement_html_v1', $json['render_profile']);
        $this->assertSame(1, $json['render_version']);
        $this->assertSame('admin_broadcast', $json['source_kind']);

        $metaRow = DatabaseRows::scalar($connection->selectOne('SELECT meta FROM message_broadcasts ORDER BY id DESC LIMIT 1'));
        $this->assertNotFalse($metaRow);
        $meta = json_decode((string)$metaRow, true);
        $this->assertSame('html', $meta['content_format']);
        $this->assertSame('announcement_html_v1', $meta['render_profile']);
        $this->assertSame(1, $meta['render_version']);
        $this->assertSame('admin_broadcast', $meta['source_kind']);
    }

    public function testBroadcastSanitizerRemovesBlockedTagsNestedInsideUnsupportedWrappers(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            school_id INTEGER,
            region_code TEXT,
            is_admin INTEGER,
            status TEXT,
            deleted_at TEXT
        )');
        $connection->unprepared('CREATE TABLE schools (
            id INTEGER PRIMARY KEY,
            name TEXT
        )');

        $connection->unprepared('CREATE TABLE system_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id TEXT
        )');

        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->statement('INSERT INTO users (id, username, email, is_admin, status, deleted_at) VALUES (?,?,?,?,?,?)', [5, 'User Five', 'user@example.com', 0, 'active', null]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 7, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $svc->expects($this->once())
            ->method('sendSystemMessage')
            ->with(
                5,
                'Alert',
                $this->callback(static function (string $content): bool {
                    return str_contains($content, '<p>Safe body</p>')
                        && !str_contains($content, '<script')
                        && !str_contains($content, 'alert(1)')
                        && !str_contains($content, '<svg')
                        && !str_contains($content, '<span');
                }),
                Message::TYPE_SYSTEM,
                'high'
            )
            ->willReturn($this->createMock(Message::class));

        $svc->expects($this->once())
            ->method('queueBroadcastEmail')
            ->with(
                $this->anything(),
                'Alert',
                $this->callback(static function (string $content): bool {
                    return str_contains($content, '<p>Safe body</p>')
                        && !str_contains($content, '<script')
                        && !str_contains($content, 'alert(1)')
                        && !str_contains($content, '<svg')
                        && !str_contains($content, '<span');
                }),
                'high',
                $this->callback(static function (array $context): bool {
                    return ($context['content_format'] ?? null) === 'html'
                        && ($context['render_profile'] ?? null) === 'announcement_html_v1'
                        && ($context['render_version'] ?? null) === 1
                        && ($context['source_kind'] ?? null) === 'admin_broadcast';
                })
            )
            ->willReturn(['queued' => true, 'recipient_count' => 1]);

        $audit->expects($this->once())->method('log');

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcast', [
            'title' => 'Alert',
            'content' => '<span><script>alert(1)</script></span><svg><script>alert(1)</script></svg><p>Safe body</p>',
            'content_format' => 'html',
            'render_profile' => 'announcement_html_v1',
            'priority' => 'high',
            'target_users' => [5]
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->sendSystemMessage($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('html', $json['content_format']);
        $this->assertSame(1, $json['render_version']);

        $row = DatabaseRows::one($connection->selectOne('SELECT content, meta FROM message_broadcasts ORDER BY id DESC LIMIT 1'));
        $this->assertIsArray($row);
        $this->assertStringContainsString('<p>Safe body</p>', (string)$row['content']);
        $this->assertStringNotContainsString('<script', (string)$row['content']);
        $this->assertStringNotContainsString('alert(1)', (string)$row['content']);
        $this->assertStringNotContainsString('<svg', (string)$row['content']);
        $this->assertStringNotContainsString('<span', (string)$row['content']);

        $meta = json_decode((string)$row['meta'], true);
        $this->assertSame(1, $meta['render_version']);
    }


    public function testSearchBroadcastRecipientsReturnsData(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->checkParameters = true;
        $stmt->method('parameter')->willReturn(true);
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id' => 10, 'username' => 'Alice', 'email' => 'alice@example.com', 'school_id' => 1, 'school_name' => 'Canonical Green', 'region_code' => 'US-UM-81', 'is_admin' => 0, 'status' => 'active'],
            ['id' => 11, 'username' => 'Bob', 'email' => 'bob@example.com', 'school_id' => 2, 'school_name' => 'Harbor Green', 'region_code' => null, 'is_admin' => 0, 'status' => 'active'],
        ]);

        $connection->method('responseFor')->willReturn($stmt);

        $region = $this->createMock(RegionService::class);
        $region->method('getRegionContext')->willReturnCallback(static function (?string $value): ?array {
            if ($value !== 'US-UM-81') {
                return null;
            }

            return [
                'region_code' => 'US-UM-81',
                'region_label' => null,
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => null,
            ];
        });

        $controller = $this->makeController($connection, $svc, $audit, $auth, null, $this->makeUserProfileViewService($region));
        $request = makeRequest('GET', '/admin/messages/broadcast/recipients', null, ['search' => 'example', 'limit' => 1]);
        $audit->expects($this->once())->method('logAdminRead')->with(
            $request,
            'admin_broadcast_recipients_searched',
            1,
            'users',
            ['page' => 1, 'limit' => 1, 'recipient_ids' => [10]]
        )->willReturn(true);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->searchBroadcastRecipients($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);
        $this->assertTrue($json['pagination']['has_more']);
        $this->assertSame(1, $json['pagination']['page']);
        $this->assertSame('Canonical Green', $json['data'][0]['school']);
        $this->assertSame('US-UM-81', $json['data'][0]['location']);
    }

    public function testSearchBroadcastRecipientsUsesNamedSearchAndIdBindings(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $bound = [];

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->checkParameters = true;
        $stmt->expects($this->exactly(6))
            ->method('parameter')
            ->willReturnCallback(function ($key, $value, ?string $type = null) use (&$bound) {
                $bound[$key] = [$value, $type];
                return true;
            });
        $stmt->expects($this->once())->method('bindings')->willReturn(true);
        $stmt->expects($this->once())->method('rows')->willReturn([]);

        $connection->expects($this->once())
            ->method('responseFor')
            ->with($this->callback(static function (string $sql): bool {
                return str_contains($sql, 'u.username LIKE :search_0')
                    && str_contains($sql, 'u.email LIKE :search_1')
                    && str_contains($sql, 'u.id IN (:include_id_0,:include_id_1)');
            }))
            ->willReturn($stmt);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('GET', '/admin/messages/broadcast/recipients', null, [
            'search' => 'alice',
            'fields' => 'username,email',
            'include_ids' => [10, 11],
            'limit' => 1,
        ]);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->searchBroadcastRecipients($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $this->assertSame('%alice%', $bound[':search_0'][0] ?? null);
        $this->assertSame('%alice%', $bound[':search_1'][0] ?? null);
        $this->assertSame(10, $bound[':include_id_0'][0] ?? null);
        $this->assertSame(11, $bound[':include_id_1'][0] ?? null);
        $this->assertSame(2, $bound[':limit'][0] ?? null);
        $this->assertSame(0, $bound[':offset'][0] ?? null);
    }

    public function testResolveExplicitRecipientsUsesDisplayAliasesFromCanonicalFields(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);

        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id' => 10, 'username' => 'Alice', 'email' => 'alice@example.com', 'school_id' => 1, 'school_name' => 'Canonical Green', 'region_code' => 'US-UM-81', 'is_admin' => 0, 'status' => 'active'],
        ]);
        $connection->method('responseFor')->willReturn($stmt);

        $region = $this->createMock(RegionService::class);
        $region->method('getRegionContext')->willReturnCallback(static function (?string $value): ?array {
            if ($value !== 'US-UM-81') {
                return null;
            }

            return [
                'region_code' => 'US-UM-81',
                'region_label' => null,
                'country_code' => 'US',
                'state_code' => 'UM-81',
                'country_name' => 'United States',
                'state_name' => null,
            ];
        });

        $controller = $this->makeController($connection, $svc, $audit, $auth, null, $this->makeUserProfileViewService($region));

        $method = new \ReflectionMethod($controller, 'resolveExplicitRecipients');
        $method->setAccessible(true);
        $result = $method->invoke($controller, [10]);

        $this->assertNull($result['error']);
        $this->assertSame('Canonical Green', $result['records'][10]['school']);
        $this->assertSame('US-UM-81', $result['records'][10]['location']);
    }

    public function testBroadcastHistoryReportsQueryFailuresWithHistoryAction(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $connection->expects($this->exactly(2))->method('responseFor')
            ->willReturnCallback(static function (string $sql): never {
                throw new \RuntimeException(str_contains($sql, 'COUNT(*)') ? 'count failed' : 'list failed');
            });
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);
        $request = makeRequest('GET', '/api/v1/admin/messages/broadcasts');
        $entries = [];
        $log = $this->createMock(ErrorLogService::class);
        $log->method('logError')->willReturnCallback(
            static function (string $type, string $message, $request, array $context) use (&$entries): int {
                $entries[] = [$type, $message, $context['action'], $context['error']];
                return 1;
            }
        );
        $controller = $this->makeController(
            $connection,
            $this->createMock(MessageService::class),
            $this->createMock(AuditLogService::class),
            $auth,
            errorLogService: $log
        );
        $response = $controller->getBroadcastHistory($request, new \Slim\Psr7\Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            ['broadcast_error', 'Failed to count broadcast history', 'getBroadcastHistory', 'count failed'],
            ['broadcast_error', 'Failed to load broadcast history', 'getBroadcastHistory', 'list failed'],
        ], $entries);
    }

    /** @dataProvider broadcastRecipientQueryOutcomes */
    public function testGetBroadcastHistoryReturnsAggregatedData(bool $recipientLookupFails): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->unprepared('CREATE TABLE messages (
            id INTEGER PRIMARY KEY,
            receiver_id INTEGER,
            title TEXT,
            content TEXT,
            is_read INTEGER,
            created_at TEXT,
            deleted_at TEXT
        )');

        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            uuid TEXT,
            username TEXT,
            email TEXT,
            status TEXT,
            is_admin INTEGER
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

        $connection->statement('INSERT INTO message_broadcasts (request_id, audit_log_id, system_log_id, error_log_ids, title, content, priority, scope, target_count, sent_count, invalid_user_ids, failed_user_ids, message_ids_snapshot, message_map_snapshot, message_id_count, content_hash, email_delivery_snapshot, filters_snapshot, meta, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
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
            json_encode([
                'content_format' => 'html',
                'render_profile' => 'announcement_html_v1',
                'render_version' => 1,
                'source_kind' => 'admin_broadcast',
            ]),
            42,
            $createdAt,
            $createdAt,
        ]);

        $msgSql = 'INSERT INTO messages (id, receiver_id, title, content, is_read, created_at, deleted_at) VALUES (?,?,?,?,?,?,?)';
        $connection->statement($msgSql, [900, 1, $title, $content, 1, $createdAt, null]);
        $connection->statement($msgSql, [901, 2, $title, $content, 0, $createdAt, null]);

        $userSql = 'INSERT INTO users (id, uuid, username, email, status, is_admin) VALUES (?,?,?,?,?,?)';
        $connection->statement($userSql, [42, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'AdminUser', 'admin@example.com', 'active', 1]);
        $connection->statement($userSql, [1, '11111111-1111-4111-8111-111111111111', 'Alice', 'alice@example.com', 'active', 0]);
        $connection->statement($userSql, [2, '22222222-2222-4222-8222-222222222222', 'Bob', 'bob@example.com', 'inactive', 0]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 99, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $request = makeRequest('GET', '/admin/messages/broadcasts');
        $audit->expects($this->once())->method('logAdminRead')->with(
            $request,
            'admin_broadcast_history_viewed',
            99,
            'message_broadcasts',
            ['page' => 1, 'limit' => 10, 'broadcast_ids' => [1]]
        )->willReturn(true);
        $errorLog = $this->createMock(ErrorLogService::class);
        if ($recipientLookupFails) {
            $connection->unprepared('ALTER TABLE messages RENAME TO archived_messages');
            $errorLog->expects($this->once())->method('logException')->with(
                $this->isInstanceOf(\Illuminate\Database\QueryException::class),
                $request,
                [
                    'controller' => 'MessageController',
                    'action' => 'getBroadcastHistory',
                    'context_message' => 'Failed to load broadcast recipients',
                ]
            );
        } else {
            $errorLog->expects($this->never())->method('logException');
        }
        $controller = $this->makeController($connection, $svc, $audit, $auth, errorLogService: $errorLog);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getBroadcastHistory($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']);

        $item = $json['data'][0];
        $this->assertSame('Hello world', $item['title']);
        if ($recipientLookupFails) {
            $this->assertSame([], $item['read_users']);
            $this->assertSame([], $item['unread_users']);
            $this->assertSame(0, $item['read_count']);
            $this->assertSame(0, $item['unread_count']);
            return;
        }
        $this->assertSame(1, $item['read_count']);
        $this->assertSame(1, $item['unread_count']);
        $this->assertSame([7], $item['invalid_user_ids']);
        $this->assertSame('AdminUser', $item['actor_username']);
        $this->assertSame('abc-123', $item['request_id']);
        $this->assertSame(321, $item['audit_log_id']);
        $this->assertSame(654, $item['system_log_id']);
        $this->assertSame([555], $item['error_log_ids']);
        $this->assertSame([900, 901], $item['message_ids']);
        $this->assertSame('html', $item['content_format']);
        $this->assertSame('announcement_html_v1', $item['render_profile']);
        $this->assertSame(1, $item['render_version']);
        $this->assertSame('admin_broadcast', $item['source_kind']);
        $this->assertSame('sent', $item['email_delivery']['status']);
        $this->assertSame([7], $item['email_delivery']['missing_email_user_ids']);
        $this->assertSame([], $item['email_delivery']['errors']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $item['read_users'][0]['user_id']);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $item['read_users'][0]['uuid']);
        $this->assertSame(1, $item['read_users'][0]['legacy_user_id']);
        $this->assertSame('active', $item['read_users'][0]['status']);
        $this->assertFalse($item['read_users'][0]['is_admin']);
        $this->assertSame('22222222-2222-4222-8222-222222222222', $item['unread_users'][0]['user_id']);
        $this->assertSame(2, $item['unread_users'][0]['legacy_user_id']);
    }

    public static function broadcastRecipientQueryOutcomes(): array
    {
        return [
            'recipients available' => [false],
            'recipient query fails' => [true],
        ];
    }

    public function testFlushBroadcastQueueMarksQueuedAsSentWithoutForce(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, deleted_at TEXT)');
        $connection->unprepared('CREATE TABLE messages (id INTEGER PRIMARY KEY, receiver_id INTEGER, title TEXT, content TEXT, is_read INTEGER DEFAULT 0, created_at TEXT, deleted_at TEXT)');
        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->statement('INSERT INTO users (id, username, email) VALUES (?,?,?)', [10, 'QueueUser', 'queue@example.com']);

        $connection->statement('INSERT INTO messages (id, receiver_id, title, content, is_read, created_at) VALUES (?,?,?,?,?,?)', [501, 10, $title, $content, 0, $createdAt]);

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

        $connection->statement('INSERT INTO message_broadcasts (id, title, content, priority, created_at, email_delivery_snapshot, message_ids_snapshot, content_hash) VALUES (?,?,?,?,?,?,?,?)', [1, $title, $content, $priority, $createdAt, $snapshot, json_encode([501]), $hash]);

        $svc = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 88, 'is_admin' => true]);
        $auth->method('isAdminUser')->willReturn(true);

        $controller = $this->makeController($connection, $svc, $audit, $auth);
        $request = makeRequest('POST', '/admin/messages/broadcasts/flush', ['limit' => 5]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->flushBroadcastEmailQueue($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame(1, $json['count']);
        $this->assertSame('sent', $json['processed'][0]['status']);

        $snapshotRow = DatabaseRows::scalar($connection->selectOne('SELECT email_delivery_snapshot FROM message_broadcasts WHERE id = 1'));
        $this->assertNotFalse($snapshotRow);
        $decoded = json_decode((string)$snapshotRow, true);
        $this->assertSame('sent', $decoded['status']);
        $this->assertNotEmpty($decoded['completed_at']);
    }

    public function testFlushBroadcastQueueForceSendFailure(): void
    {
        $connection = DatabaseFixture::sqlite();

        $connection->unprepared('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, deleted_at TEXT)');
        $connection->unprepared('CREATE TABLE messages (id INTEGER PRIMARY KEY, receiver_id INTEGER, title TEXT, content TEXT, is_read INTEGER DEFAULT 0, created_at TEXT, deleted_at TEXT)');
        $connection->unprepared('CREATE TABLE message_broadcasts (
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

        $connection->statement('INSERT INTO users (id, username, email) VALUES (?,?,?)', [20, 'ForceUser', 'force@example.com']);

        $connection->statement('INSERT INTO messages (id, receiver_id, title, content, is_read, created_at) VALUES (?,?,?,?,?,?)', [701, 20, $title, $content, 0, $createdAt]);

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

        $connection->statement('INSERT INTO message_broadcasts (id, title, content, priority, created_at, email_delivery_snapshot, message_ids_snapshot, content_hash) VALUES (?,?,?,?,?,?,?,?)', [2, $title, $content, $priority, $createdAt, $snapshot, json_encode([701]), $hash]);

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

        $controller = $this->makeController($connection, $svc, $audit, $auth, $emailService);
        $request = makeRequest('POST', '/admin/messages/broadcasts/flush', ['limit' => 5, 'force' => 1]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->flushBroadcastEmailQueue($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('failed', $json['processed'][0]['status']);
        $this->assertContains('mailer down', $json['processed'][0]['errors']);

        $snapshotRow = DatabaseRows::scalar($connection->selectOne('SELECT email_delivery_snapshot FROM message_broadcasts WHERE id = 2'));
        $this->assertNotFalse($snapshotRow);
        $decoded = json_decode((string)$snapshotRow, true);
        $this->assertSame('failed', $decoded['status']);
        $this->assertContains('mailer down', $decoded['errors']);
    }
}



