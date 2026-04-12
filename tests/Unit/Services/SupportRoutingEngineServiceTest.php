<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\SupportRoutingEngineService;
use CarbonTrack\Services\SupportRoutingTriageService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportRoutingEngineServiceTest extends TestCase
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
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('user');
            $table->boolean('is_admin')->default(false);
            $table->string('status')->default('active');
            $table->integer('group_id')->nullable();
            $table->text('quota_override')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('user_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->text('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
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

        self::$capsule->schema()->create('support_ticket_feedback', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('user_id');
            $table->integer('rated_user_id');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_tags', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug');
            $table->string('name');
            $table->string('color')->default('emerald');
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

        self::$capsule->schema()->create('support_ticket_automation_rules', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('match_category')->nullable();
            $table->string('match_priority')->nullable();
            $table->text('match_weekdays')->nullable();
            $table->string('match_time_start')->nullable();
            $table->string('match_time_end')->nullable();
            $table->string('timezone')->default('Asia/Shanghai');
            $table->integer('assign_to')->nullable();
            $table->decimal('score_boost', 10, 2)->default(0);
            $table->integer('required_agent_level')->nullable();
            $table->text('skill_hints_json')->nullable();
            $table->text('add_tag_ids')->nullable();
            $table->boolean('stop_processing')->default(false);
            $table->integer('trigger_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_assignee_profiles', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('level')->default(1);
            $table->text('skills_json')->nullable();
            $table->text('languages_json')->nullable();
            $table->integer('max_active_tickets')->default(10);
            $table->boolean('is_auto_assignable')->default(true);
            $table->text('weight_overrides_json')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_routing_settings', function (Blueprint $table): void {
            $table->increments('id');
            $table->boolean('ai_enabled')->default(false);
            $table->integer('ai_timeout_ms')->default(12000);
            $table->integer('due_soon_minutes')->default(30);
            $table->text('weights_json')->nullable();
            $table->text('fallback_json')->nullable();
            $table->text('defaults_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        self::$capsule->schema()->create('support_ticket_routing_runs', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->string('trigger')->default('created');
            $table->boolean('used_ai')->default(false);
            $table->string('fallback_reason')->nullable();
            $table->text('triage_json')->nullable();
            $table->text('matched_rule_ids_json')->nullable();
            $table->text('candidate_scores_json')->nullable();
            $table->integer('winner_user_id')->nullable();
            $table->decimal('winner_score', 12, 2)->nullable();
            $table->text('summary_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'support_ticket_routing_runs',
            'support_routing_settings',
            'support_assignee_profiles',
            'support_ticket_automation_rules',
            'support_ticket_tag_assignments',
            'support_ticket_tags',
            'support_ticket_feedback',
            'support_ticket_messages',
            'support_tickets',
            'user_groups',
            'users',
        ] as $table) {
            self::$capsule->table($table)->delete();
        }
    }

    public function testRouteTicketUsesGroupLevelRequirement(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('user_groups')->insert([
            'id' => 1,
            'name' => 'VIP',
            'code' => 'vip',
            'config' => json_encode(['support_routing' => ['min_agent_level' => 4, 'routing_weight' => 1.5]]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('users')->insert([
            ['id' => 1, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'group_id' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'username' => 'junior', 'email' => 'junior@example.com', 'role' => 'support', 'group_id' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'username' => 'senior', 'email' => 'senior@example.com', 'role' => 'support', 'group_id' => null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        self::$capsule->table('support_assignee_profiles')->insert([
            ['user_id' => 2, 'level' => 2, 'skills_json' => json_encode([]), 'languages_json' => json_encode([]), 'max_active_tickets' => 10, 'is_auto_assignable' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => 3, 'level' => 5, 'skills_json' => json_encode([]), 'languages_json' => json_encode([]), 'max_active_tickets' => 10, 'is_auto_assignable' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'weights_json' => json_encode(['group_weight' => 15]),
            'fallback_json' => json_encode(['default_feedback_rating' => 3.5]),
            'defaults_json' => json_encode(['min_agent_level' => 1]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 101,
            'user_id' => 1,
            'subject' => 'VIP complaint',
            'category' => 'business_issue',
            'status' => 'open',
            'priority' => 'normal',
            'sla_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'ticket_id' => 101,
            'sender_id' => 1,
            'sender_role' => 'user',
            'sender_name' => 'requester',
            'body' => 'Please escalate this',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $result = $engine->routeTicket(101, 'created');
        $ticket = self::$capsule->table('support_tickets')->where('id', 101)->first();

        $this->assertSame(3, $result['assigned_to']);
        $this->assertSame('smart', $ticket->assignment_source);
        $this->assertSame(3, (int) $ticket->assigned_to);
        $this->assertNotNull($ticket->last_routing_run_id);
    }

    public function testRouteTicketCanUseSupportUserWithoutProfileRow(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            ['id' => 21, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 22, 'username' => 'support-no-profile', 'email' => 'support@example.com', 'role' => 'support', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);

        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'weights_json' => json_encode(['group_weight' => 15]),
            'fallback_json' => json_encode(['default_feedback_rating' => 3.5]),
            'defaults_json' => json_encode(['min_agent_level' => 1]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::$capsule->table('support_tickets')->insert([
            'id' => 202,
            'user_id' => 21,
            'subject' => 'Profileless support candidate',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'low',
            'sla_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'ticket_id' => 202,
            'sender_id' => 21,
            'sender_role' => 'user',
            'sender_name' => 'requester',
            'body' => 'Please help',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $result = $engine->routeTicket(202, 'created');
        $ticket = self::$capsule->table('support_tickets')->where('id', 202)->first();

        $this->assertSame(22, $result['assigned_to']);
        $this->assertSame(22, (int) $ticket->assigned_to);
        $this->assertSame('smart', $ticket->assignment_source);
    }

    public function testNotifyAssigneeLogsFailedWhenNoChannelSucceeds(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        $loggedPayloads = [];
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->exactly(3))
            ->method('log')
            ->willReturnCallback(static function (array $payload) use (&$loggedPayloads): bool {
                $loggedPayloads[] = $payload;
                return true;
            });

        $messageService = $this->createMock(MessageService::class);
        $messageService->method('sendSystemMessage')->willThrowException(new \RuntimeException('message failed'));

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('sendMessageNotification')->willThrowException(new \RuntimeException('email failed'));

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $logger,
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class)),
            $messageService,
            $emailService
        );

        $method = new \ReflectionMethod($engine, 'notifyAssignee');
        $method->setAccessible(true);
        $method->invoke($engine, ['id' => 99, 'username' => 'supporter', 'email' => 'support@example.com'], 'Subject', 'Body', 123);

        $finalNotificationLog = null;
        foreach ($loggedPayloads as $payload) {
            if (($payload['action'] ?? null) === 'support_routing_assignee_notified') {
                $finalNotificationLog = $payload;
                break;
            }
        }

        $this->assertNotNull($finalNotificationLog);
        $this->assertSame('failed', $finalNotificationLog['status'] ?? null);
        $this->assertFalse($finalNotificationLog['data']['message_sent'] ?? true);
        $this->assertFalse($finalNotificationLog['data']['email_sent'] ?? true);
    }

    public function testBuildSlaSummaryMarksTicketAsDueSoon(): void
    {
        $now = date('Y-m-d H:i:s');
        $tz = new \DateTimeZone('Asia/Shanghai');
        $base = new \DateTimeImmutable('now', $tz);
        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'due_soon_minutes' => 30,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $summary = $engine->buildSlaSummaryForTicket([
            'status' => 'open',
            'sla_status' => 'pending',
            'first_support_response_at' => null,
            'first_response_due_at' => $base->modify('+20 minutes')->format('Y-m-d H:i:s'),
            'resolution_due_at' => $base->modify('+3 hours')->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame('due_soon', $summary['display_state']);
        $this->assertSame('first_response', $summary['active_target']);
        $this->assertSame('due_soon', $summary['first_response']['state']);
    }

    public function testRunSlaSweepDoesNotReEscalateAlreadyEscalatedTicket(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            ['id' => 31, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 32, 'username' => 'supporter', 'email' => 'support@example.com', 'role' => 'support', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_assignee_profiles')->insert([
            'user_id' => 32,
            'level' => 2,
            'skills_json' => json_encode([]),
            'languages_json' => json_encode([]),
            'max_active_tickets' => 10,
            'is_auto_assignable' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 303,
            'user_id' => 31,
            'subject' => 'Already escalated',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'urgent',
            'assigned_to' => 32,
            'assignment_source' => 'smart',
            'assignment_locked' => 0,
            'first_response_due_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'resolution_due_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'sla_status' => 'escalated',
            'escalation_level' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $result = $engine->runSlaSweep();
        $ticket = self::$capsule->table('support_tickets')->where('id', 303)->first();

        $this->assertSame(['processed' => 0, 'breached' => 0, 'rerouted' => 0], $result);
        $this->assertSame(3, (int) $ticket->escalation_level);
        $this->assertSame('escalated', $ticket->sla_status);
        $this->assertSame(0, (int) self::$capsule->table('support_ticket_routing_runs')->count());
    }

    public function testRunSlaSweepRetriesPreviouslyBreachedTicketWithoutIncrementingEscalationAgain(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            ['id' => 41, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 42, 'username' => 'supporter', 'email' => 'support@example.com', 'role' => 'support', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_assignee_profiles')->insert([
            'user_id' => 42,
            'level' => 4,
            'skills_json' => json_encode([]),
            'languages_json' => json_encode([]),
            'max_active_tickets' => 10,
            'is_auto_assignable' => 1,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 404,
            'user_id' => 41,
            'subject' => 'Retry breached reroute',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'low',
            'assigned_to' => 42,
            'assignment_source' => 'smart',
            'assignment_locked' => 0,
            'first_response_due_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'resolution_due_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'sla_status' => 'breached',
            'escalation_level' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'ticket_id' => 404,
            'sender_id' => 41,
            'sender_role' => 'user',
            'sender_name' => 'requester',
            'body' => 'Please retry',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $result = $engine->runSlaSweep();
        $ticket = self::$capsule->table('support_tickets')->where('id', 404)->first();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['breached']);
        $this->assertSame(1, $result['rerouted']);
        $this->assertSame(2, (int) $ticket->escalation_level);
        $this->assertSame('escalated', $ticket->sla_status);
    }

    public function testRunSlaSweepKeepsTicketBreachedWhenRerouteFindsNoWinner(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            ['id' => 51, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_routing_settings')->insert([
            'id' => 1,
            'ai_enabled' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 505,
            'user_id' => 51,
            'subject' => 'No winner available',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'high',
            'assignment_locked' => 0,
            'first_response_due_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
            'resolution_due_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'sla_status' => 'pending',
            'escalation_level' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_messages')->insert([
            'ticket_id' => 505,
            'sender_id' => 51,
            'sender_role' => 'user',
            'sender_name' => 'requester',
            'body' => 'Please route me',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->method('logSystemEvent')->willReturn(true);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $result = $engine->runSlaSweep();
        $ticket = self::$capsule->table('support_tickets')->where('id', 505)->first();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['breached']);
        $this->assertSame(0, $result['rerouted']);
        $this->assertSame('breached', $ticket->sla_status);
        $this->assertSame(1, (int) $ticket->escalation_level);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame(1, (int) self::$capsule->table('support_ticket_routing_runs')->count());
    }

    public function testRoutingSummaryKeepsTopFactorsMachineReadable(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('support_ticket_routing_runs')->insert([
            'ticket_id' => 101,
            'trigger' => 'created',
            'used_ai' => 1,
            'summary_json' => json_encode([
                'top_factors' => [
                    'severity' => 12.5,
                    'priority' => 9.0,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $summary = $engine->getRoutingSummaryForTicket(101);
        $runs = $engine->getRoutingRunsForTicket(101);

        $this->assertIsArray($summary['top_factors']);
        $this->assertSame(['severity' => 12.5, 'priority' => 9.0], $summary['top_factors']);
        $this->assertSame(['severity' => 12.5, 'priority' => 9.0], $runs[0]['summary']['top_factors']);
    }

    public function testRoutingSummaryTrimsWhitespaceOnlyTopFactorsListEntries(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('support_ticket_routing_runs')->insert([
            'ticket_id' => 102,
            'trigger' => 'created',
            'used_ai' => 1,
            'summary_json' => json_encode([
                'top_factors' => ['  severity  ', '   ', null, 0, 'priority'],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $summary = $engine->getRoutingSummaryForTicket(102);

        $this->assertSame(['severity', '0', 'priority'], $summary['top_factors']);
    }

    public function testRoutingRunsPreserveStoredCandidateNames(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('support_ticket_routing_runs')->insert([
            'ticket_id' => 103,
            'trigger' => 'created',
            'used_ai' => 1,
            'winner_user_id' => 12,
            'winner_score' => 66.5,
            'candidate_scores_json' => json_encode([
                ['candidate' => ['id' => 11, 'username' => 'alpha'], 'candidate_id' => 11, 'total_score' => 61.2],
                ['candidate' => ['id' => 12, 'username' => 'beta'], 'candidate_id' => 12, 'total_score' => 66.5],
            ]),
            'summary_json' => json_encode(['winner_label' => 'beta']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $engine = new SupportRoutingEngineService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new SupportRoutingTriageService(null, $this->createMock(LoggerInterface::class))
        );

        $runs = $engine->getRoutingRunsForTicket(103);

        $this->assertSame('alpha', $runs[0]['candidate_scores'][0]['candidate']['username']);
        $this->assertSame('beta', $runs[0]['candidate_scores'][1]['candidate']['username']);
        $this->assertSame('beta', $runs[0]['summary']['winner_label']);
    }
}
