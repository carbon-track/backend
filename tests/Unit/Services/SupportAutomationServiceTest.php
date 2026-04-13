<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\SupportAutomationService;
use CarbonTrack\Services\UserProfileViewService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportAutomationServiceTest extends TestCase
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

        self::$capsule->schema()->create('schools', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });

        self::$capsule->schema()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->string('role')->default('user');
            $table->boolean('is_admin')->default(false);
            $table->string('status')->default('active');
            $table->integer('school_id')->nullable();
            $table->string('region_code')->nullable();
            $table->integer('group_id')->nullable();
            $table->timestamp('lastlgn')->nullable();
            $table->text('admin_notes')->nullable();
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

        self::$capsule->schema()->create('support_ticket_automation_rules', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('description')->nullable();
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
            $table->boolean('ai_enabled')->default(true);
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
            'support_ticket_feedback',
            'support_ticket_tag_assignments',
            'support_ticket_tags',
            'support_ticket_automation_rules',
            'support_tickets',
            'schools',
            'users',
        ] as $table) {
            self::$capsule->table($table)->delete();
        }
    }

    public function testListAssignableUsersUsesSchoolLookupWithoutLegacySchoolColumn(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('schools')->insert([
            'id' => 9,
            'name' => 'Green Academy',
        ]);
        self::$capsule->table('users')->insert([
            'id' => 1,
            'uuid' => 'requester-uuid',
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'is_admin' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('users')->insert([
            'id' => 2,
            'uuid' => 'support-uuid',
            'username' => 'supporter',
            'email' => 'support@example.com',
            'role' => 'support',
            'is_admin' => 0,
            'status' => 'active',
            'school_id' => 9,
            'region_code' => 'US-CA',
            'group_id' => 1,
            'lastlgn' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 10,
            'user_id' => 1,
            'subject' => 'Critical bug',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'urgent',
            'assigned_to' => 2,
            'assignment_source' => 'rule',
            'assigned_rule_id' => 3,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $users = $this->makeService()->listAssignableUsers();

        $this->assertCount(1, $users);
        $this->assertSame('Green Academy', $users[0]['school']);
        $this->assertSame('US-CA', $users[0]['region_code']);
        $this->assertStringContainsString('California', (string) $users[0]['location']);
        $this->assertSame(1, $users[0]['assigned_total_count']);
        $this->assertSame(1, $users[0]['open_count']);
    }

    public function testGetAssignableUserDetailUsesSchoolLookupWithoutLegacySchoolColumn(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('schools')->insert([
            'id' => 9,
            'name' => 'Green Academy',
        ]);
        self::$capsule->table('users')->insert([
            'id' => 2,
            'uuid' => 'support-uuid',
            'username' => 'supporter',
            'email' => 'support@example.com',
            'role' => 'support',
            'is_admin' => 0,
            'status' => 'active',
            'school_id' => 9,
            'region_code' => 'US-CA',
            'group_id' => 1,
            'lastlgn' => $now,
            'admin_notes' => 'On-call this week',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $detail = $this->makeService()->getAssignableUserDetail(2);

        $this->assertNotNull($detail);
        $this->assertSame('Green Academy', $detail['school']);
        $this->assertSame('US-CA', $detail['region_code']);
        $this->assertStringContainsString('California', (string) $detail['location']);
        $this->assertSame('On-call this week', $detail['admin_notes']);
        $this->assertSame([], $detail['recent_tickets']);
    }

    public function testGetAssignableUserDetailIncludesFeedbackSummaryAndEntries(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            [
                'id' => 1,
                'uuid' => 'reviewer-uuid',
                'username' => 'requester',
                'email' => 'requester@example.com',
                'role' => 'user',
                'is_admin' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'uuid' => 'support-uuid',
                'username' => 'supporter',
                'email' => 'support@example.com',
                'role' => 'support',
                'is_admin' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 10,
                'user_id' => 1,
                'subject' => 'Bug fix follow-up',
                'category' => 'website_bug',
                'status' => 'resolved',
                'priority' => 'high',
                'assigned_to' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 11,
                'user_id' => 1,
                'subject' => 'Account issue',
                'category' => 'account',
                'status' => 'closed',
                'priority' => 'normal',
                'assigned_to' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        self::$capsule->table('support_ticket_feedback')->insert([
            [
                'ticket_id' => 10,
                'user_id' => 1,
                'rated_user_id' => 2,
                'rating' => 5,
                'comment' => '很专业',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 11,
                'user_id' => 1,
                'rated_user_id' => 2,
                'rating' => 4,
                'comment' => '回复很快',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            ],
        ]);

        $detail = $this->makeService()->getAssignableUserDetail(2);

        $this->assertNotNull($detail);
        $this->assertSame(4.5, $detail['feedback_summary']['average_rating']);
        $this->assertSame(2, $detail['feedback_summary']['rating_count']);
        $this->assertCount(5, $detail['feedback_summary']['distribution']);
        $this->assertSame(1, $detail['feedback_summary']['distribution'][0]['count']);
        $this->assertCount(2, $detail['feedback_entries']);
        $this->assertSame('Bug fix follow-up', $detail['feedback_entries'][0]['ticket']['subject']);
        $this->assertSame('requester', $detail['feedback_entries'][0]['reviewer']['username']);
        $this->assertSame('很专业', $detail['feedback_entries'][0]['comment']);
    }

    public function testGetAssignableUserDetailKeepsFeedbackEntryWhenReviewerIsSoftDeleted(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            'id' => 1,
            'uuid' => 'reviewer-uuid',
            'username' => 'requester',
            'email' => 'requester@example.com',
            'role' => 'user',
            'is_admin' => 0,
            'status' => 'active',
            'deleted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('users')->insert([
            'id' => 2,
            'uuid' => 'support-uuid',
            'username' => 'supporter',
            'email' => 'support@example.com',
            'role' => 'support',
            'is_admin' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 10,
            'user_id' => 1,
            'subject' => 'Historical feedback',
            'category' => 'website_bug',
            'status' => 'resolved',
            'priority' => 'high',
            'assigned_to' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_feedback')->insert([
            'ticket_id' => 10,
            'user_id' => 1,
            'rated_user_id' => 2,
            'rating' => 5,
            'comment' => '仍应保留',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $detail = $this->makeService()->getAssignableUserDetail(2);

        $this->assertNotNull($detail);
        $this->assertSame(1, $detail['feedback_summary']['rating_count']);
        $this->assertCount(1, $detail['feedback_entries']);
        $this->assertSame(1, $detail['feedback_entries'][0]['reviewer']['id']);
        $this->assertNull($detail['feedback_entries'][0]['reviewer']['username']);
        $this->assertNull($detail['feedback_entries'][0]['reviewer']['email']);
    }

    public function testApplyRulesAddsTagsAndPreservesTicketAssignmentForScoring(): void
    {
        $now = date('Y-m-d H:i:s');
        self::$capsule->table('users')->insert([
            ['id' => 1, 'username' => 'requester', 'email' => 'requester@example.com', 'role' => 'user', 'is_admin' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'username' => 'supporter', 'email' => 'support@example.com', 'role' => 'support', 'is_admin' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_tickets')->insert([
            'id' => 10,
            'user_id' => 1,
            'subject' => 'Critical bug',
            'category' => 'website_bug',
            'status' => 'open',
            'priority' => 'urgent',
            'assigned_to' => null,
            'assignment_source' => null,
            'assigned_rule_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_ticket_tags')->insert([
            'id' => 7,
            'slug' => 'hotfix',
            'name' => 'Hotfix',
            'color' => 'rose',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $weekday = strtolower((new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('D'));
        self::$capsule->table('support_ticket_automation_rules')->insert([
            'id' => 3,
            'name' => 'Urgent bug routing',
            'is_active' => 1,
            'sort_order' => 1,
            'match_category' => 'website_bug',
            'match_priority' => 'urgent',
            'match_weekdays' => json_encode([$weekday]),
            'match_time_start' => '00:00',
            'match_time_end' => '23:59',
            'timezone' => 'Asia/Shanghai',
            'assign_to' => 2,
            'score_boost' => 20,
            'required_agent_level' => 2,
            'skill_hints_json' => json_encode(['billing', 'bug']),
            'add_tag_ids' => json_encode([7]),
            'stop_processing' => 1,
            'trigger_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = $this->makeService();
        $result = $service->applyRulesToTicket(10, null, 'created');

        $this->assertNull($result['assigned_to']);
        $this->assertNull($result['assignment_source']);
        $this->assertNull($result['assigned_rule_id']);
        $this->assertCount(1, $result['applied_rules']);
        $this->assertSame(2, $result['applied_rules'][0]['assigned_to']);
        $this->assertSame(20.0, $result['applied_rules'][0]['score_boost']);
        $this->assertSame(2, $result['applied_rules'][0]['required_agent_level']);
        $this->assertCount(1, $result['tags']);
        $this->assertSame('hotfix', $result['tags'][0]['slug']);

        $ticket = self::$capsule->table('support_tickets')->where('id', 10)->first();
        $this->assertNull($ticket->assigned_to);
        $this->assertNull($ticket->assignment_source);
        $this->assertNull($ticket->assigned_rule_id);
        $this->assertSame(1, self::$capsule->table('support_ticket_tag_assignments')->count());
    }

    public function testReportsAggregateCountsAcrossRulesTagsAndAssignments(): void
    {
        $now = date('Y-m-d H:i:s');
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        self::$capsule->table('users')->insert([
            ['id' => 1, 'username' => 'supporter', 'email' => 'support@example.com', 'role' => 'support', 'is_admin' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_ticket_tags')->insert([
            ['id' => 7, 'slug' => 'hotfix', 'name' => 'Hotfix', 'color' => 'rose', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        self::$capsule->table('support_ticket_automation_rules')->insert([
            'id' => 3,
            'name' => 'Urgent bug routing',
            'is_active' => 1,
            'sort_order' => 1,
            'timezone' => 'Asia/Shanghai',
            'trigger_count' => 4,
            'last_triggered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        self::$capsule->table('support_tickets')->insert([
            [
                'id' => 10,
                'user_id' => 1,
                'subject' => 'Critical bug',
                'category' => 'website_bug',
                'status' => 'open',
                'priority' => 'urgent',
                'assigned_to' => 1,
                'assignment_source' => 'smart',
                'assigned_rule_id' => 3,
                'sla_status' => 'escalated',
                'created_at' => $now,
                'updated_at' => $now,
                'resolved_at' => null,
            ],
            [
                'id' => 11,
                'user_id' => 1,
                'subject' => 'Question',
                'category' => 'account',
                'status' => 'resolved',
                'priority' => 'normal',
                'assigned_to' => null,
                'assignment_source' => null,
                'assigned_rule_id' => null,
                'sla_status' => 'resolved',
                'created_at' => $yesterday,
                'updated_at' => $now,
                'resolved_at' => $now,
            ],
        ]);
        self::$capsule->table('support_ticket_tag_assignments')->insert([
            'ticket_id' => 10,
            'tag_id' => 7,
            'source_type' => 'rule',
            'rule_id' => 3,
            'created_at' => $now,
        ]);

        $service = $this->makeService();
        $reports = $service->getReports(['days' => 14]);

        $this->assertSame(2, $reports['summary']['total']);
        $this->assertSame(1, $reports['summary']['smart_assignment_count']);
        $this->assertSame(1, $reports['summary']['sla_breach_count']);
        $this->assertSame(1, $reports['summary']['unassigned']);
        $this->assertNotNull($reports['summary']['avg_resolution_hours']);
        $this->assertNotEmpty($reports['timeline']);
        $this->assertContains('website_bug', array_column($reports['by_category'], 'key'));
        $this->assertSame('hotfix', $reports['by_tag'][0]['slug']);
        $this->assertSame(4, $reports['rule_hits'][0]['trigger_count']);
    }

    public function testReportsAggregateRoutingOutcomesByTrigger(): void
    {
        $now = date('Y-m-d H:i:s');

        self::$capsule->table('support_ticket_routing_runs')->insert([
            [
                'ticket_id' => 10,
                'trigger' => 'created',
                'used_ai' => 1,
                'winner_user_id' => 2,
                'winner_score' => 88.5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 11,
                'trigger' => 'created',
                'used_ai' => 0,
                'winner_user_id' => null,
                'winner_score' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ticket_id' => 12,
                'trigger' => 'sla_breach',
                'used_ai' => 1,
                'winner_user_id' => 3,
                'winner_score' => 91.0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $reports = $this->makeService()->getReports(['days' => 14]);
        $byTrigger = [];
        foreach ($reports['routing_outcomes'] as $row) {
            $byTrigger[$row['trigger']] = $row;
        }

        $this->assertSame(2, $byTrigger['created']['count'] ?? null);
        $this->assertSame(1, $byTrigger['created']['no_winner_count'] ?? null);
        $this->assertSame(1, $byTrigger['created']['used_ai_count'] ?? null);
        $this->assertSame(1, $byTrigger['sla_breach']['count'] ?? null);
        $this->assertSame(0, $byTrigger['sla_breach']['no_winner_count'] ?? null);
        $this->assertSame(1, $byTrigger['sla_breach']['used_ai_count'] ?? null);
    }

    private function makeService(): SupportAutomationService
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->method('log')->willReturn(true);

        return new SupportAutomationService(
            self::$capsule->getConnection()->getPdo(),
            $this->createMock(LoggerInterface::class),
            $audit,
            $this->createMock(ErrorLogService::class),
            new UserProfileViewService(new RegionService())
        );
    }
}
