<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Models\File;
use CarbonTrack\Models\User;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\FileMetadataService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\NotificationPreferenceService;
use CarbonTrack\Services\SupportAutomationService;
use CarbonTrack\Services\SupportTicketService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportTicketServiceTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::$capsule->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('user');
            $table->boolean('is_admin')->default(false);
            $table->string('status')->nullable();
            $table->integer('school_id')->nullable();
            $table->string('school')->nullable();
            $table->string('region_code')->nullable();
            $table->string('location')->nullable();
            $table->integer('group_id')->nullable();
            $table->timestamp('lastlgn')->nullable();
            $table->text('admin_notes')->nullable();
            $table->integer('notification_email_mask')->default(0);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_tickets', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('subject');
            $table->string('category');
            $table->string('status');
            $table->string('priority')->default('normal');
            $table->integer('assigned_to')->nullable();
            $table->string('assignment_source')->nullable();
            $table->integer('assigned_rule_id')->nullable();
            $table->boolean('assignment_locked')->default(false);
            $table->timestamp('first_support_response_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->string('sla_status')->default('pending');
            $table->integer('escalation_level')->default(0);
            $table->integer('last_routing_run_id')->nullable();
            $table->string('last_reply_by_role')->nullable();
            $table->timestamp('last_replied_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_messages', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('sender_id')->nullable();
            $table->string('sender_role')->nullable();
            $table->string('sender_name')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_attachments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('message_id');
            $table->integer('file_id')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->integer('size')->default(0);
            $table->string('entity_type')->default('support_ticket_message');
            $table->timestamp('created_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_feedback', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('user_id');
            $table->integer('rated_user_id');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['ticket_id', 'user_id', 'rated_user_id'], 'uniq_ticket_user_rated');
        });

        self::$capsule->schema()->create('support_ticket_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug');
            $table->string('name');
            $table->string('color')->default('emerald');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_tag_assignments', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('tag_id');
            $table->string('source_type')->default('rule');
            $table->integer('rule_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_transfer_requests', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('requested_by');
            $table->integer('from_assignee')->nullable();
            $table->integer('to_assignee');
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->text('review_note')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule !== null) {
            self::$capsule->table('support_ticket_transfer_requests')->delete();
            self::$capsule->table('support_ticket_tag_assignments')->delete();
            self::$capsule->table('support_ticket_tags')->delete();
            self::$capsule->table('support_ticket_feedback')->delete();
            self::$capsule->table('support_ticket_attachments')->delete();
            self::$capsule->table('support_ticket_messages')->delete();
            self::$capsule->table('support_tickets')->delete();
            self::$capsule->table('users')->delete();
        }
    }

    public function testListSupportTicketsUsesDistinctSearchBindings(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);
        $listExecuteParams = null;
        $countExecuteParams = null;

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$listExecuteParams) {
                $listExecuteParams = $params;
                return true;
            });
        $listStmt->expects($this->once())->method('fetchAll')->willReturn([]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (array $params) use (&$countExecuteParams) {
                $countExecuteParams = $params;
                return true;
            });
        $countStmt->expects($this->once())->method('fetchColumn')->willReturn(0);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($listStmt, $countStmt) {
                static $prepareCalls = 0;
                $prepareCalls++;
                $this->assertStringContainsString('t.subject LIKE :search_subject', $sql);
                $this->assertStringContainsString('requester.username LIKE :search_username', $sql);
                $this->assertStringContainsString('requester.email LIKE :search_email', $sql);
                return $prepareCalls === 1 ? $listStmt : $countStmt;
            });

        $service = new SupportTicketService($pdo, $logger, $audit, $errorLog, $fileMetadata);
        $result = $service->listSupportTickets(['id' => 7, 'is_admin' => true], ['q' => 'billing']);

        $this->assertSame([], $result['items']);
        $this->assertSame('%billing%', $listExecuteParams['search_subject'] ?? null);
        $this->assertSame('%billing%', $listExecuteParams['search_username'] ?? null);
        $this->assertSame('%billing%', $listExecuteParams['search_email'] ?? null);
        $this->assertSame('%billing%', $countExecuteParams['search_subject'] ?? null);
    }

    public function testSupportReplyRejectsAttachmentOutsideTicketScope(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 50,
            'user_id' => (int) $requester->id,
            'subject' => 'Cross-ticket leak check',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportUser->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $foreignFile = new File([
            'id' => 501,
            'file_path' => 'support-tickets/2026/04/foreign-evidence.png',
            'original_name' => 'foreign-evidence.png',
            'mime_type' => 'image/png',
            'size' => 1234,
            'user_id' => (int) $requester->id,
        ]);
        $fileMetadata->expects($this->once())
            ->method('findByFilePath')
            ->with('support-tickets/2026/04/foreign-evidence.png')
            ->willReturn($foreignFile);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment is not authorized for this ticket');

        $service->addSupportMessage(
            ['id' => (int) $supportUser->id, 'role' => 'support', 'is_support' => true, 'username' => 'support-a'],
            50,
            [
                'content' => 'Attaching foreign file should fail',
                'attachments' => ['support-tickets/2026/04/foreign-evidence.png'],
            ]
        );
    }

    public function testSupportReplyCanReuseAttachmentAlreadyScopedToTicket(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 51,
            'user_id' => (int) $requester->id,
            'subject' => 'Scoped attachment reuse',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportUser->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'id' => 5101,
            'ticket_id' => 51,
            'sender_id' => (int) $requester->id,
            'sender_role' => 'user',
            'sender_name' => 'requester',
            'body' => 'Original attachment',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_attachments')->insert([
            'ticket_id' => 51,
            'message_id' => 5101,
            'file_id' => 601,
            'file_path' => 'support-tickets/2026/04/reused-proof.png',
            'original_name' => 'reused-proof.png',
            'mime_type' => 'image/png',
            'size' => 2048,
            'entity_type' => 'support_ticket_message',
            'created_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $reusedFile = new File([
            'id' => 601,
            'file_path' => 'support-tickets/2026/04/reused-proof.png',
            'original_name' => 'reused-proof.png',
            'mime_type' => 'image/png',
            'size' => 2048,
            'user_id' => (int) $requester->id,
        ]);
        $fileMetadata->expects($this->once())
            ->method('findByFilePath')
            ->with('support-tickets/2026/04/reused-proof.png')
            ->willReturn($reusedFile);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $result = $service->addSupportMessage(
            ['id' => (int) $supportUser->id, 'role' => 'support', 'is_support' => true, 'username' => 'support-a'],
            51,
            [
                'content' => 'Reusing already-scoped attachment',
                'attachments' => ['support-tickets/2026/04/reused-proof.png'],
            ]
        );

        $attachments = self::$capsule->table('support_ticket_attachments')
            ->where('ticket_id', 51)
            ->where('file_path', 'support-tickets/2026/04/reused-proof.png')
            ->get();

        $this->assertSame(2, $attachments->count());
        $this->assertSame(51, $result['id']);
    }

    public function testUpdateTicketFromSupportSendsUserSupportNotification(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'is_admin' => 0,
            'notification_email_mask' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'supporter',
            'email' => 'support@example.com',
            'role' => 'support',
            'is_admin' => 0,
            'notification_email_mask' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 1,
            'user_id' => (int) $requester->id,
            'subject' => 'Broken dashboard',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportUser->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $messages = $this->createMock(MessageService::class);
        $email = $this->createMock(EmailService::class);

        $audit->method('log')->willReturn(true);

        $messages->expects($this->once())
            ->method('sendSystemMessage')
            ->with(
                (int) $requester->id,
                'Support ticket #1 updated',
                $this->stringContains('Status: open -> in_progress'),
                'support_ticket',
                'normal',
                'support_ticket',
                1,
                false
            );

        $email->expects($this->once())
            ->method('sendMessageNotification')
            ->with(
                'requester@example.com',
                'requester',
                'Support ticket #1 updated',
                $this->stringContains('Priority: normal -> high'),
                NotificationPreferenceService::CATEGORY_SUPPORT,
                'normal'
            )
            ->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            $email,
            $messages,
            null
        );

        $result = $service->updateTicketFromSupport(
            ['id' => (int) $supportUser->id, 'role' => 'support', 'is_support' => true],
            1,
            ['status' => 'in_progress', 'priority' => 'high']
        );

        $this->assertSame('in_progress', $result['status']);
        $this->assertSame('high', $result['priority']);
    }

    public function testListSupportTicketsCanFilterUnassignedQueue(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $admin = User::create([
            'username' => 'admin-user',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'is_admin' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 1,
                'user_id' => (int) $requester->id,
                'subject' => 'Unassigned ticket',
                'category' => 'website_bug',
                'status' => 'open',
                'priority' => 'normal',
                'assigned_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'user_id' => (int) $requester->id,
                'subject' => 'Assigned ticket',
                'category' => 'account',
                'status' => 'in_progress',
                'priority' => 'high',
                'assigned_to' => (int) $supportUser->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $automation = $this->createMock(SupportAutomationService::class);
        $audit->method('log')->willReturn(true);
        $automation->method('getTagsForTicketIds')->willReturn([]);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            null,
            null,
            null,
            $automation
        );

        $result = $service->listSupportTickets(
            ['id' => (int) $admin->id, 'role' => 'admin', 'is_admin' => true],
            ['assigned_to' => 0]
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
    }

    public function testSupportUserOnlySeesAssignedTickets(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 1,
                'user_id' => (int) $requester->id,
                'subject' => 'Mine',
                'category' => 'website_bug',
                'status' => 'open',
                'priority' => 'normal',
                'assigned_to' => (int) $supportA->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'user_id' => (int) $requester->id,
                'subject' => 'Not mine',
                'category' => 'account',
                'status' => 'open',
                'priority' => 'normal',
                'assigned_to' => (int) $supportB->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'user_id' => (int) $requester->id,
                'subject' => 'Unassigned',
                'category' => 'other',
                'status' => 'open',
                'priority' => 'normal',
                'assigned_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $automation = $this->createMock(SupportAutomationService::class);
        $audit->method('log')->willReturn(true);
        $automation->method('getTagsForTicketIds')->willReturn([]);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            null,
            null,
            null,
            $automation
        );

        $result = $service->listSupportTickets(
            ['id' => (int) $supportA->id, 'role' => 'support', 'is_support' => true],
            ['assigned_to' => 0]
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame(1, $result['items'][0]['id']);
    }

    public function testSupportUserCannotViewOtherAssigneeTicketDetail(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 10,
            'user_id' => (int) $requester->id,
            'subject' => 'Other assignee ticket',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportB->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ticket not found');

        $service->getTicketDetailForSupport(['id' => (int) $supportA->id, 'role' => 'support', 'is_support' => true], 10);
    }

    public function testTransferTargetCanViewPendingTransferTicketDetail(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 20,
            'user_id' => (int) $requester->id,
            'subject' => 'Pending transfer ticket',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportA->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_ticket_transfer_requests')->insert([
            'ticket_id' => 20,
            'requested_by' => (int) $supportA->id,
            'from_assignee' => (int) $supportA->id,
            'to_assignee' => (int) $supportB->id,
            'reason' => 'Need database expertise',
            'status' => SupportTicketService::TRANSFER_STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $detail = $service->getTicketDetailForSupport(
            ['id' => (int) $supportB->id, 'role' => 'support', 'is_support' => true],
            20
        );

        $this->assertSame(20, $detail['id']);
        $this->assertCount(1, $detail['transfer_requests']);
        $this->assertSame((int) $supportB->id, $detail['transfer_requests'][0]['to_assignee']);
    }

    public function testTransferTargetCanListPendingTransferTicketsSeparately(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 30,
                'user_id' => (int) $requester->id,
                'subject' => 'Pending transfer to me',
                'category' => 'website_bug',
                'status' => 'open',
                'priority' => 'high',
                'assigned_to' => (int) $supportA->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 31,
                'user_id' => (int) $requester->id,
                'subject' => 'Assigned to me',
                'category' => 'account',
                'status' => 'in_progress',
                'priority' => 'normal',
                'assigned_to' => (int) $supportB->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        self::$capsule->table('support_ticket_transfer_requests')->insert([
            'ticket_id' => 30,
            'requested_by' => (int) $supportA->id,
            'from_assignee' => (int) $supportA->id,
            'to_assignee' => (int) $supportB->id,
            'reason' => 'Need review by support-b',
            'status' => SupportTicketService::TRANSFER_STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $automation = $this->createMock(SupportAutomationService::class);
        $audit->method('log')->willReturn(true);
        $automation->method('getTagsForTicketIds')->willReturn([]);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            null,
            null,
            null,
            $automation
        );

        $result = $service->listSupportTickets(
            ['id' => (int) $supportB->id, 'role' => 'support', 'is_support' => true],
            ['pending_transfer_target' => 1]
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame(30, $result['items'][0]['id']);
        $this->assertSame('Need review by support-b', $result['items'][0]['pending_transfer_request']['reason']);
        $this->assertSame((int) $supportA->id, $result['items'][0]['pending_transfer_request']['from_assignee']);
    }

    public function testAdminCanListPendingTransferTicketsAddressedToSelf(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $admin = User::create([
            'username' => 'admin-user',
            'email' => 'admin@example.com',
            'role' => 'admin',
            'is_admin' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 40,
                'user_id' => (int) $requester->id,
                'subject' => 'Pending transfer to admin',
                'category' => 'business_issue',
                'status' => 'open',
                'priority' => 'high',
                'assigned_to' => (int) $supportA->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 41,
                'user_id' => (int) $requester->id,
                'subject' => 'Another queue ticket',
                'category' => 'account',
                'status' => 'open',
                'priority' => 'normal',
                'assigned_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        self::$capsule->table('support_ticket_transfer_requests')->insert([
            'ticket_id' => 40,
            'requested_by' => (int) $supportA->id,
            'from_assignee' => (int) $supportA->id,
            'to_assignee' => (int) $admin->id,
            'reason' => 'Admin review needed',
            'status' => SupportTicketService::TRANSFER_STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $automation = $this->createMock(SupportAutomationService::class);
        $audit->method('log')->willReturn(true);
        $automation->method('getTagsForTicketIds')->willReturn([]);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            null,
            null,
            null,
            $automation
        );

        $result = $service->listSupportTickets(
            ['id' => (int) $admin->id, 'role' => 'admin', 'is_admin' => true],
            ['pending_transfer_target' => 1]
        );

        $this->assertCount(1, $result['items']);
        $this->assertSame(40, $result['items'][0]['id']);
        $this->assertSame('Admin review needed', $result['items'][0]['pending_transfer_request']['reason']);
        $this->assertSame((int) $admin->id, $result['items'][0]['pending_transfer_request']['to_assignee']);
    }

    public function testCreateTransferRequestCreatesPendingEntry(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 1,
            'user_id' => (int) $requester->id,
            'subject' => 'Billing mismatch',
            'category' => 'business_issue',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportA->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $messages = $this->createMock(MessageService::class);
        $email = $this->createMock(EmailService::class);
        $messages->expects($this->once())
            ->method('sendSystemMessage')
            ->with((int) $supportB->id, 'Transfer request for ticket #1', $this->stringContains('A transfer request is waiting for your review.'), 'support_ticket', 'normal', 'support_ticket', 1, false);
        $email->expects($this->once())
            ->method('sendMessageNotification')
            ->with('support-b@example.com', 'support-b', 'Transfer request for ticket #1', $this->stringContains('A transfer request is waiting for your review.'), NotificationPreferenceService::CATEGORY_SUPPORT, 'normal');

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            $email,
            $messages
        );

        $result = $service->createTransferRequest(
            ['id' => (int) $supportA->id, 'role' => 'support', 'is_support' => true, 'username' => 'support-a'],
            1,
            ['to_assignee' => (int) $supportB->id, 'reason' => 'Need a different owner']
        );

        $this->assertSame('pending', $result['status']);
        $this->assertSame((int) $supportB->id, $result['to_assignee']);
        $this->assertSame(1, self::$capsule->table('support_ticket_transfer_requests')->count());
    }

    public function testReviewTransferRequestApprovesWhenTargetAcceptsTicket(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportA = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportB = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 1,
            'user_id' => (int) $requester->id,
            'subject' => 'Billing mismatch',
            'category' => 'business_issue',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportA->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $request = $service->createTransferRequest(
            ['id' => (int) $supportA->id, 'role' => 'support', 'is_support' => true, 'username' => 'support-a'],
            1,
            ['to_assignee' => (int) $supportB->id, 'reason' => 'Need a different owner']
        );

        $result = $service->reviewTransferRequest(
            ['id' => (int) $supportB->id, 'role' => 'support', 'is_support' => true, 'username' => 'support-b'],
            (int) $request['id'],
            ['status' => 'approved', 'review_note' => 'I can take this one']
        );

        $ticketRow = self::$capsule->table('support_tickets')->where('id', 1)->first();

        $this->assertSame('approved', $result['status']);
        $this->assertSame((int) $supportB->id, $ticketRow->assigned_to);
        $this->assertSame('manual', $ticketRow->assignment_source);
        $this->assertSame(0, (int) $ticketRow->assignment_locked);
    }

    public function testSubmitTicketFeedbackCreatesEntryForHandledSupportAgent(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 1,
            'user_id' => (int) $requester->id,
            'subject' => 'Resolved issue',
            'category' => 'account',
            'status' => 'resolved',
            'priority' => 'normal',
            'assigned_to' => (int) $supportUser->id,
            'resolved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'ticket_id' => 1,
            'sender_id' => (int) $supportUser->id,
            'sender_role' => 'support',
            'sender_name' => 'support-a',
            'body' => 'Issue fixed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $result = $service->submitTicketFeedback(
            ['id' => (int) $requester->id, 'role' => 'user', 'username' => 'requester'],
            1,
            [
                'rated_user_id' => (int) $supportUser->id,
                'rating' => 5,
                'comment' => '处理很快',
            ]
        );

        $feedbackRow = self::$capsule->table('support_ticket_feedback')->where('ticket_id', 1)->first();

        $this->assertNotNull($feedbackRow);
        $this->assertSame(5, $feedbackRow->rating);
        $this->assertSame('处理很快', $feedbackRow->comment);
        $this->assertCount(1, $result['feedback']);
        $this->assertSame((int) $supportUser->id, $result['feedback'][0]['rated_user_id']);
        $this->assertSame((int) $supportUser->id, $result['feedback_candidates'][0]['id']);
    }

    public function testSubmitTicketFeedbackRequiresResolvedOrClosedTicket(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $supportUser = User::create([
            'username' => 'support-a',
            'email' => 'support-a@example.com',
            'role' => 'support',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 2,
            'user_id' => (int) $requester->id,
            'subject' => 'Still open',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to' => (int) $supportUser->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $audit->method('log')->willReturn(true);

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Feedback is only available after the ticket is resolved or closed');

        $service->submitTicketFeedback(
            ['id' => (int) $requester->id, 'role' => 'user', 'username' => 'requester'],
            2,
            [
                'rated_user_id' => (int) $supportUser->id,
                'rating' => 4,
            ]
        );
    }

    public function testAdminAssignmentLocksTicketAndNotifiesTarget(): void
    {
        $now = date('Y-m-d H:i:s');
        $requester = User::create([
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'notification_email_mask' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assignee = User::create([
            'username' => 'support-b',
            'email' => 'support-b@example.com',
            'role' => 'support',
            'notification_email_mask' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 3,
            'user_id' => (int) $requester->id,
            'subject' => 'Escalation needed',
            'category' => 'business_issue',
            'status' => 'open',
            'priority' => 'high',
            'assigned_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $audit = $this->createMock(AuditLogService::class);
        $errorLog = $this->createMock(ErrorLogService::class);
        $fileMetadata = $this->createMock(FileMetadataService::class);
        $messages = $this->createMock(MessageService::class);
        $email = $this->createMock(EmailService::class);
        $audit->method('log')->willReturn(true);
        $messages->expects($this->exactly(2))
            ->method('sendSystemMessage')
            ->withConsecutive(
                [
                    (int) $requester->id,
                    'Support ticket #3 updated',
                    $this->stringContains('Assigned handler'),
                    'support_ticket',
                    'normal',
                    'support_ticket',
                    3,
                    false,
                ],
                [
                    (int) $assignee->id,
                    'Ticket #3 assigned to you',
                    $this->stringContains('An administrator assigned ticket #3 to you.'),
                    'support_ticket',
                    'normal',
                    'support_ticket',
                    3,
                    false,
                ]
            );
        $email->expects($this->exactly(2))
            ->method('sendMessageNotification')
            ->withConsecutive(
                [
                    'requester@example.com',
                    'requester',
                    'Support ticket #3 updated',
                    $this->stringContains('Assigned handler'),
                    NotificationPreferenceService::CATEGORY_SUPPORT,
                    'normal',
                ],
                [
                    'support-b@example.com',
                    'support-b',
                    'Ticket #3 assigned to you',
                    $this->stringContains('An administrator assigned ticket #3 to you.'),
                    NotificationPreferenceService::CATEGORY_SUPPORT,
                    'normal',
                ]
            );

        $service = new SupportTicketService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $errorLog,
            $fileMetadata,
            $email,
            $messages
        );

        $service->updateTicketFromSupport(
            ['id' => 99, 'role' => 'admin', 'is_admin' => true, 'username' => 'admin-user'],
            3,
            ['assigned_to' => (int) $assignee->id]
        );

        $ticketRow = self::$capsule->table('support_tickets')->where('id', 3)->first();
        $this->assertSame((int) $assignee->id, $ticketRow->assigned_to);
        $this->assertSame('manual', $ticketRow->assignment_source);
        $this->assertSame(1, (int) $ticketRow->assignment_locked);
    }

}
