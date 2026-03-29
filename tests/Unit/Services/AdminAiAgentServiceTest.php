<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AdminAiAgentService;
use CarbonTrack\Services\Ai\LlmClientInterface;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LlmLogService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PDO;
use Psr\Log\NullLogger;

class AdminAiAgentServiceTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);
        return $pdo;
    }

    public function testChatCreatesConversationAndRestoresHistoryFromLogs(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $activityId = '550e8400-e29b-41d4-a716-446655440001';
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, uuid) VALUES (2, 'review_user', 'review@example.com', 'active', 0, '550e8400-e29b-41d4-a716-4466554400b2')");
        $pdo->exec("INSERT INTO carbon_records (id, user_id, activity_id, status, date, carbon_saved, points_earned) VALUES ('rec-read-1', 2, '{$activityId}', 'pending', '2026-03-20', 3.5, 8)");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'get_pending_carbon_records',
                    'payload' => [
                        'limit' => 5,
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'get_pending_carbon_records',
                        'label' => 'Get pending carbon records',
                        'description' => 'Read pending carbon records.',
                        'api' => ['payloadTemplate' => ['status' => 'pending', 'limit' => 5, 'record_ids' => []]],
                        'requires' => [],
                        'contextHints' => [],
                        'risk_level' => 'read',
                        'requires_confirmation' => false,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $result = $service->chat(null, '查看待审核碳记录', [], null, [
            'request_id' => 'req-read-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['conversation_id']);
        $this->assertStringContainsString('待处理记录', $result['message']);
        $this->assertSame(1, $result['conversation']['summary']['llm_calls']);
        $this->assertCount(2, array_filter($result['conversation']['messages'], static fn (array $item): bool => ($item['kind'] ?? null) === 'message'));
        $conversationCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_ai_conversations WHERE conversation_id IS NOT NULL")->fetchColumn();
        $messageCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_ai_messages WHERE conversation_id IS NOT NULL")->fetchColumn();
        $this->assertSame(1, $conversationCount);
        $this->assertSame(3, $messageCount);

        $llmCount = (int) $pdo->query("SELECT COUNT(*) FROM llm_logs WHERE conversation_id IS NOT NULL")->fetchColumn();
        $this->assertSame(1, $llmCount);
    }

    public function testChatRestoresConversationFromLlmLogsWhenAuditWritesFail(): void
    {
        $pdo = $this->makePdo();
        $llmLogService = new LlmLogService($pdo, new Logger('test'));
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $activityId = '550e8400-e29b-41d4-a716-446655440001';
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, uuid) VALUES (2, 'review_user', 'review@example.com', 'active', 0, '550e8400-e29b-41d4-a716-4466554400b4')");
        $pdo->exec("INSERT INTO carbon_records (id, user_id, activity_id, status, date, carbon_saved, points_earned) VALUES ('rec-read-2', 2, '{$activityId}', 'pending', '2026-03-20', 3.5, 8)");

        $auditLogService = $this->getMockBuilder(AuditLogService::class)
            ->setConstructorArgs([$pdo, new Logger('test')])
            ->onlyMethods(['logAdminOperation', 'getLastInsertId'])
            ->getMock();
        $auditLogService->method('logAdminOperation')->willReturn(false);
        $auditLogService->method('getLastInsertId')->willReturn(null);

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'get_pending_carbon_records',
                    'payload' => [
                        'limit' => 5,
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'get_pending_carbon_records',
                        'label' => 'Get pending carbon records',
                        'description' => 'Read pending carbon records.',
                        'api' => ['payloadTemplate' => ['status' => 'pending', 'limit' => 5, 'record_ids' => []]],
                        'requires' => [],
                        'contextHints' => [],
                        'risk_level' => 'read',
                        'requires_confirmation' => false,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $result = $service->chat(null, '查看待审核碳记录', [], null, [
            'request_id' => 'req-read-fallback-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($result['success']);
        $visibleMessages = array_values(array_filter($result['conversation']['messages'], static fn (array $item): bool => ($item['kind'] ?? null) === 'message'));
        $this->assertCount(2, $visibleMessages);
        $this->assertSame('查看待审核碳记录', $visibleMessages[0]['content']);
        $this->assertStringContainsString('待处理记录', (string) $visibleMessages[1]['content']);

        $conversations = $service->listConversations(['admin_id' => 1]);
        $this->assertCount(1, $conversations);
        $this->assertSame($result['conversation_id'], $conversations[0]['conversation_id']);
        $this->assertSame(2, $conversations[0]['message_count']);
        $this->assertStringContainsString('待处理记录', (string) $conversations[0]['last_message_preview']);
        $storedCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_ai_messages WHERE conversation_id = '{$result['conversation_id']}'")->fetchColumn();
        $this->assertSame(3, $storedCount);
    }

    public function testChatFallsBackToKeywordMatchedActionWhenModelReturnsNoToolCall(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $activityId = '550e8400-e29b-41d4-a716-446655440001';
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, uuid) VALUES (2, 'review_user', 'review@example.com', 'active', 0, '550e8400-e29b-41d4-a716-4466554400b7')");
        $pdo->exec("INSERT INTO carbon_records (id, user_id, activity_id, status, date, carbon_saved, points_earned) VALUES ('rec-read-3', 2, '{$activityId}', 'pending', '2026-03-20', 2.4, 6)");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->plainTextResponse('我先想想。'),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'get_pending_carbon_records',
                        'label' => 'Get pending carbon records',
                        'description' => 'Read pending carbon records.',
                        'api' => ['payloadTemplate' => ['status' => 'pending', 'limit' => 5, 'record_ids' => []]],
                        'requires' => [],
                        'contextHints' => [],
                        'risk_level' => 'read',
                        'requires_confirmation' => false,
                        'keywords' => ['待审核碳记录', '待审核记录', '碳记录', '待审批'],
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $result = $service->chat(null, '帮我查看当前待审核碳记录，并按优先级给出处理建议。', [], null, [
            'request_id' => 'req-read-heuristic-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('待处理记录', $result['message']);
        $toolEvents = array_values(array_filter(
            $result['conversation']['messages'],
            static fn (array $item): bool => ($item['kind'] ?? null) === 'tool'
        ));
        $this->assertCount(1, $toolEvents);
        $this->assertSame('get_pending_carbon_records', $toolEvents[0]['meta']['data']['action_name']);
    }

    public function testWriteActionRequiresConfirmationAndExecutesAfterDecision(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $activityId = '550e8400-e29b-41d4-a716-446655440001';
        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, uuid) VALUES (2, 'review_user', 'review@example.com', 'active', 0, 10, '550e8400-e29b-41d4-a716-4466554400b3')");
        $pdo->exec("INSERT INTO carbon_records (id, user_id, activity_id, status, date, carbon_saved, points_earned) VALUES ('rec-write-1', 2, '{$activityId}', 'pending', '2026-03-20', 3.5, 8)");

        $messageService = $this->getMockBuilder(MessageService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendCarbonRecordReviewSummary'])
            ->getMock();
        $messageService->expects($this->once())
            ->method('sendCarbonRecordReviewSummary');

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'approve_carbon_records',
                    'payload' => [
                        'record_ids' => ['rec-write-1'],
                        'review_note' => '批量通过',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'approve_carbon_records',
                        'label' => 'Approve carbon reduction records',
                        'description' => 'Approve pending records.',
                        'api' => ['payloadTemplate' => ['action' => 'approve', 'record_ids' => [], 'review_note' => null]],
                        'requires' => ['record_ids'],
                        'contextHints' => [],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService,
            null,
            $messageService
        );

        $proposalResult = $service->chat(null, '审批 rec-write-1', [], null, [
            'request_id' => 'req-write-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($proposalResult['success']);
        $this->assertSame('pending', $proposalResult['conversation']['pending_actions'][0]['status']);
        $statusBefore = $pdo->query("SELECT status FROM carbon_records WHERE id = 'rec-write-1'")->fetchColumn();
        $this->assertSame('pending', $statusBefore);

        $proposalId = $proposalResult['conversation']['pending_actions'][0]['proposal_id'];
        $decisionResult = $service->chat(
            $proposalResult['conversation_id'],
            null,
            [],
            ['proposal_id' => $proposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-write-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertTrue($decisionResult['success']);
        $this->assertStringContainsString('已批准', $decisionResult['message']);
        $statusAfter = $pdo->query("SELECT status FROM carbon_records WHERE id = 'rec-write-1'")->fetchColumn();
        $this->assertSame('approved', $statusAfter);

        $executedCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin_ai_action_executed'")->fetchColumn();
        $this->assertSame(1, $executedCount);
    }

    public function testListConversationsSupportsStatusModelDateAndPendingFilters(): void
    {
        $pdo = $this->makePdo();
        $auditLogService = new AuditLogService($pdo, new Logger('test'));
        $llmLogService = new LlmLogService($pdo, new Logger('test'));

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([]),
            new NullLogger(),
            ['model' => 'test-model'],
            ['agent' => ['max_history_messages' => 12]],
            $llmLogService,
            $auditLogService
        );

        $pdo->exec("
            INSERT INTO admin_ai_conversations (conversation_id, admin_id, title, last_message_preview, started_at, last_activity_at)
            VALUES
            ('admin-ai-11111111', 7, '会话一', '待确认', '2026-03-20 10:00:00', '2026-03-20 10:05:00'),
            ('admin-ai-22222222', 9, '会话二', '会话二', '2026-03-18 09:00:00', '2026-03-18 09:00:00')
        ");
        $pdo->exec("
            INSERT INTO admin_ai_messages (conversation_id, kind, role, action, status, content, meta_json, created_at)
            VALUES
            ('admin-ai-11111111', 'message', 'user', 'admin_ai_user_message', 'success', '会话一', '{\"data\":{\"visible_text\":\"会话一\"}}', '2026-03-20 10:00:00'),
            ('admin-ai-11111111', 'action_proposed', NULL, 'admin_ai_action_proposed', 'pending', '待确认', '{\"data\":{\"action_name\":\"approve_carbon_records\",\"label\":\"Approve\",\"summary\":\"待确认\",\"payload\":{\"record_ids\":[\"rec-1\"]},\"risk_level\":\"write\"}}', '2026-03-20 10:05:00'),
            ('admin-ai-22222222', 'message', 'user', 'admin_ai_user_message', 'success', '会话二', '{\"data\":{\"visible_text\":\"会话二\"}}', '2026-03-18 09:00:00')
        ");
        $pdo->exec("
            INSERT INTO llm_logs (request_id, actor_type, actor_id, conversation_id, turn_no, source, model, prompt, response_raw, status, total_tokens, created_at)
            VALUES
            ('req-a', 'admin', 7, 'admin-ai-11111111', 1, '/admin/ai/chat', 'gpt-5.4', 'hello', '{\"ok\":true}', 'success', 11, '2026-03-20 10:01:00'),
            ('req-b', 'admin', 9, 'admin-ai-22222222', 1, '/admin/ai/chat', 'gemini-2.5-flash', 'hello', '{\"ok\":true}', 'success', 9, '2026-03-18 09:01:00')
        ");

        $filtered = $service->listConversations([
            'admin_id' => 7,
            'status' => 'waiting_confirmation',
            'model' => 'gpt-5.4',
            'date_from' => '2026-03-19',
            'date_to' => '2026-03-21',
            'has_pending_action' => 'true',
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('admin-ai-11111111', $filtered[0]['conversation_id']);
        $this->assertSame(7, $filtered[0]['admin_id']);
        $this->assertSame('waiting_confirmation', $filtered[0]['status']);
        $this->assertSame(1, $filtered[0]['pending_action_count']);
        $this->assertSame('gpt-5.4', $filtered[0]['last_model']);
    }

    public function testSearchUsersReadActionReturnsUserMatches(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, uuid) VALUES (2, 'alice_admin', 'alice@example.com', 'active', 0, 36, '550e8400-e29b-41d4-a716-4466554400c2')");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'search_users',
                    'payload' => [
                        'search' => 'alice',
                        'limit' => 10,
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'search_users',
                        'label' => 'Search users',
                        'description' => 'Search admin users list.',
                        'api' => ['payloadTemplate' => ['search' => '', 'limit' => 10]],
                        'requires' => [],
                        'contextHints' => [],
                        'risk_level' => 'read',
                        'requires_confirmation' => false,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $result = $service->chat(null, '查一下 alice 这个用户', [], null, [
            'request_id' => 'req-user-search',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('匹配到 1 位用户', $result['message']);
        $this->assertStringContainsString('alice_admin', $result['message']);
    }

    public function testAdjustUserPointsUsesSelectedUserContextAndExecutesAfterConfirmation(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, uuid) VALUES (2, 'points_user', 'points@example.com', 'active', 0, 10, '550e8400-e29b-41d4-a716-4466554400c3')");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'adjust_user_points',
                    'payload' => [
                        'delta' => 25,
                        'reason' => 'manual compensation',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'adjust_user_points',
                        'label' => 'Adjust user points',
                        'description' => 'Adjust user points.',
                        'api' => ['payloadTemplate' => ['user_id' => null, 'user_uuid' => null, 'delta' => null, 'reason' => null]],
                        'requires' => [
                            ['anyOf' => ['user_id', 'user_uuid'], 'label' => 'user_id_or_uuid'],
                            'delta',
                        ],
                        'contextHints' => ['selectedUserId'],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $proposal = $service->chat(null, '给当前用户加 25 分', ['selectedUserId' => 2], null, [
            'request_id' => 'req-adjust-points-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $this->assertTrue($proposal['success']);
        $this->assertSame('pending', $proposal['conversation']['pending_actions'][0]['status']);
        $this->assertSame(10, (int) $pdo->query("SELECT points FROM users WHERE id = 2")->fetchColumn());

        $proposalId = $proposal['conversation']['pending_actions'][0]['proposal_id'];
        $decision = $service->chat(
            $proposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $proposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-adjust-points-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertTrue($decision['success']);
        $this->assertStringContainsString('已为用户 points_user 调整积分 25', $decision['message']);
        $this->assertSame(35, (int) $pdo->query("SELECT points FROM users WHERE id = 2")->fetchColumn());
    }

    public function testBadgeAwardAndRevokeUseBadgeServiceAfterConfirmation(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, uuid) VALUES (2, 'badge_user', 'badge@example.com', 'active', 0, 10, '550e8400-e29b-41d4-a716-4466554400d3')");
        $pdo->exec("INSERT INTO achievement_badges (id, uuid, code, name_zh, name_en, is_active) VALUES (9, '550e8400-e29b-41d4-a716-4466554400e3', 'pioneer', '先锋徽章', 'Pioneer Badge', 1)");

        $badgeService = $this->getMockBuilder(BadgeService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['awardBadge', 'revokeBadge'])
            ->getMock();

        $badgeService->expects($this->once())
            ->method('awardBadge')
            ->with(
                9,
                2,
                $this->callback(function (array $context) use ($pdo): bool {
                    $pdo->exec("INSERT INTO user_badges (user_id, badge_id, status, awarded_at, awarded_by, source, notes) VALUES (2, 9, 'awarded', '2026-03-23 08:00:00', 1, 'manual', '季度补发')");
                    return ($context['admin_id'] ?? null) === 1
                        && ($context['notes'] ?? null) === '季度补发'
                        && ($context['source'] ?? null) === 'manual';
                })
            );

        $badgeService->expects($this->once())
            ->method('revokeBadge')
            ->with(9, 2, 1, '误发撤回')
            ->willReturnCallback(function () use ($pdo): bool {
                $pdo->exec("UPDATE user_badges SET status = 'revoked', revoked_at = '2026-03-23 08:30:00', revoked_by = 1, notes = '误发撤回' WHERE user_id = 2 AND badge_id = 9");
                return true;
            });

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'award_badge_to_user',
                    'payload' => [
                        'badge_id' => 9,
                        'notes' => '季度补发',
                    ],
                ]),
                $this->toolResponse('manage_admin', [
                    'action' => 'revoke_badge_from_user',
                    'payload' => [
                        'badge_id' => 9,
                        'notes' => '误发撤回',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'award_badge_to_user',
                        'label' => 'Award badge to user',
                        'description' => 'Grant a badge.',
                        'api' => ['payloadTemplate' => ['badge_id' => null, 'user_id' => null, 'user_uuid' => null, 'notes' => null]],
                        'requires' => [
                            'badge_id',
                            ['anyOf' => ['user_id', 'user_uuid'], 'label' => 'user_id_or_uuid'],
                        ],
                        'contextHints' => ['selectedUserId'],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                    [
                        'name' => 'revoke_badge_from_user',
                        'label' => 'Revoke badge from user',
                        'description' => 'Revoke a badge.',
                        'api' => ['payloadTemplate' => ['badge_id' => null, 'user_id' => null, 'user_uuid' => null, 'notes' => null]],
                        'requires' => [
                            'badge_id',
                            ['anyOf' => ['user_id', 'user_uuid'], 'label' => 'user_id_or_uuid'],
                        ],
                        'contextHints' => ['selectedUserId'],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService,
            null,
            null,
            $badgeService
        );

        $awardProposal = $service->chat(null, '给当前用户发先锋徽章', ['selectedUserId' => 2], null, [
            'request_id' => 'req-badge-award-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);
        $awardProposalId = $awardProposal['conversation']['pending_actions'][0]['proposal_id'];
        $awardDecision = $service->chat(
            $awardProposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $awardProposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-badge-award-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('发放徽章 先锋徽章', $awardDecision['message']);
        $this->assertSame('awarded', $pdo->query("SELECT status FROM user_badges WHERE user_id = 2 AND badge_id = 9")->fetchColumn());

        $revokeProposal = $service->chat(null, '把当前用户的先锋徽章撤掉', ['selectedUserId' => 2], null, [
            'request_id' => 'req-badge-revoke-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);
        $revokeProposalId = $revokeProposal['conversation']['pending_actions'][0]['proposal_id'];
        $revokeDecision = $service->chat(
            $revokeProposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $revokeProposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-badge-revoke-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('撤销用户 badge_user 的徽章 先锋徽章', $revokeDecision['message']);
        $this->assertSame('revoked', $pdo->query("SELECT status FROM user_badges WHERE user_id = 2 AND badge_id = 9")->fetchColumn());
    }

    public function testUpdateUserStatusUsesSelectedUserContextAndExecutesAfterConfirmation(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, uuid, admin_notes) VALUES (2, 'status_user', 'status@example.com', 'active', 0, 10, '550e8400-e29b-41d4-a716-4466554400f3', null)");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'update_user_status',
                    'payload' => [
                        'status' => 'banned',
                        'admin_notes' => 'spam reports confirmed',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'update_user_status',
                        'label' => 'Update user status',
                        'description' => 'Update user status.',
                        'api' => ['payloadTemplate' => ['user_id' => null, 'user_uuid' => null, 'status' => null, 'admin_notes' => null]],
                        'requires' => [
                            ['anyOf' => ['user_id', 'user_uuid'], 'label' => 'user_id_or_uuid'],
                            'status',
                        ],
                        'contextHints' => ['selectedUserId'],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $proposal = $service->chat(null, '封禁当前用户', ['selectedUserId' => 2], null, [
            'request_id' => 'req-user-status-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);
        $proposalId = $proposal['conversation']['pending_actions'][0]['proposal_id'];
        $decision = $service->chat(
            $proposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $proposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-user-status-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('状态更新为 banned', $decision['message']);
        $this->assertSame('banned', $pdo->query("SELECT status FROM users WHERE id = 2")->fetchColumn());
        $this->assertSame('spam reports confirmed', $pdo->query("SELECT admin_notes FROM users WHERE id = 2")->fetchColumn());
    }

    public function testCreateUserActionRequiresConfirmationAndPersistsHashedPassword(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'create_user',
                    'payload' => [
                        'username' => 'new_admin_user',
                        'email' => 'new-admin@example.com',
                        'password' => 'TempPass#2026',
                        'status' => 'active',
                        'is_admin' => true,
                        'school_id' => 1,
                        'admin_notes' => 'created by admin ai',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'create_user',
                        'label' => 'Create user',
                        'description' => 'Create a new user account.',
                        'api' => ['payloadTemplate' => [
                            'username' => null,
                            'email' => null,
                            'password' => null,
                            'status' => 'active',
                            'is_admin' => false,
                            'school_id' => null,
                            'group_id' => null,
                            'region_code' => null,
                            'admin_notes' => null,
                        ]],
                        'requires' => ['username', 'email', 'password'],
                        'contextHints' => [],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $proposal = $service->chat(null, '新增一个管理员账号', [], null, [
            'request_id' => 'req-create-user-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);

        $pendingAction = $proposal['conversation']['pending_actions'][0];
        $this->assertArrayHasKey('password_hash', $pendingAction['payload']);
        $this->assertArrayNotHasKey('password', $pendingAction['payload']);
        $this->assertTrue((bool) ($pendingAction['payload']['password_provided'] ?? false));

        $proposalLogData = (string) $pdo->query("SELECT data FROM audit_logs WHERE action = 'admin_ai_action_proposed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertStringContainsString('password_hash', $proposalLogData);
        $this->assertStringNotContainsString('TempPass#2026', $proposalLogData);

        $proposalId = $pendingAction['proposal_id'];
        $decision = $service->chat(
            $proposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $proposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-create-user-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('已创建用户 new_admin_user', $decision['message']);

        $user = $pdo->query("SELECT username, email, password, status, is_admin, admin_notes FROM users WHERE username = 'new_admin_user'")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($user);
        $this->assertSame('new-admin@example.com', $user['email']);
        $this->assertSame('active', $user['status']);
        $this->assertSame('1', (string) $user['is_admin']);
        $this->assertSame('created by admin ai', $user['admin_notes']);
        $this->assertNotSame('TempPass#2026', $user['password']);
        $this->assertTrue(password_verify('TempPass#2026', (string) $user['password']));
    }

    public function testProductStatusAndInventoryActionsExecuteAfterConfirmation(): void
    {
        $pdo = $this->makePdo();
        $logger = new Logger('test');
        $auditLogService = new AuditLogService($pdo, $logger);
        $llmLogService = new LlmLogService($pdo, $logger);
        $errorLogService = new ErrorLogService($pdo, new NullLogger());

        $pdo->exec("INSERT INTO products (id, name, stock, status, points_required) VALUES (5, 'Eco Bottle', 12, 'inactive', 80)");

        $service = new AdminAiAgentService(
            $pdo,
            new QueueLlmClient([
                $this->toolResponse('manage_admin', [
                    'action' => 'update_product_status',
                    'payload' => [
                        'product_id' => 5,
                        'status' => 'active',
                    ],
                ]),
                $this->toolResponse('manage_admin', [
                    'action' => 'adjust_product_inventory',
                    'payload' => [
                        'product_id' => 5,
                        'stock_delta' => 8,
                        'reason' => 'manual restock',
                    ],
                ]),
            ]),
            new NullLogger(),
            ['model' => 'test-model'],
            [
                'agent' => ['max_history_messages' => 12],
                'managementActions' => [
                    [
                        'name' => 'update_product_status',
                        'label' => 'Update product status',
                        'description' => 'Set product status.',
                        'api' => ['payloadTemplate' => ['product_id' => null, 'status' => null]],
                        'requires' => ['product_id', 'status'],
                        'contextHints' => [],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                    [
                        'name' => 'adjust_product_inventory',
                        'label' => 'Adjust product inventory',
                        'description' => 'Adjust stock.',
                        'api' => ['payloadTemplate' => ['product_id' => null, 'stock_delta' => null, 'target_stock' => null, 'reason' => null]],
                        'requires' => [
                            'product_id',
                            ['anyOf' => ['stock_delta', 'target_stock'], 'label' => 'stock_delta_or_target_stock'],
                        ],
                        'contextHints' => [],
                        'risk_level' => 'write',
                        'requires_confirmation' => true,
                    ],
                ],
            ],
            $llmLogService,
            $auditLogService,
            $errorLogService
        );

        $statusProposal = $service->chat(null, '把 5 号商品上架', [], null, [
            'request_id' => 'req-product-status-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);
        $statusProposalId = $statusProposal['conversation']['pending_actions'][0]['proposal_id'];
        $statusDecision = $service->chat(
            $statusProposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $statusProposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-product-status-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('Eco Bottle 已更新为 active', $statusDecision['message']);
        $this->assertSame('active', $pdo->query("SELECT status FROM products WHERE id = 5")->fetchColumn());

        $inventoryProposal = $service->chat(null, '给 5 号商品补货 8 件', [], null, [
            'request_id' => 'req-product-stock-1',
            'actor_type' => 'admin',
            'actor_id' => 1,
            'source' => '/admin/ai/chat',
        ]);
        $inventoryProposalId = $inventoryProposal['conversation']['pending_actions'][0]['proposal_id'];
        $inventoryDecision = $service->chat(
            $inventoryProposal['conversation_id'],
            null,
            [],
            ['proposal_id' => $inventoryProposalId, 'outcome' => 'confirm'],
            [
                'request_id' => 'req-product-stock-2',
                'actor_type' => 'admin',
                'actor_id' => 1,
                'source' => '/admin/ai/chat',
            ]
        );

        $this->assertStringContainsString('库存已从 12 调整到 20', $inventoryDecision['message']);
        $this->assertSame(20, (int) $pdo->query("SELECT stock FROM products WHERE id = 5")->fetchColumn());
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    private function toolResponse(string $toolName, array $arguments): array
    {
        return [
            'id' => 'resp-test',
            'model' => 'test-model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 12, 'total_tokens' => 22],
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call-1',
                        'type' => 'function',
                        'function' => [
                            'name' => $toolName,
                            'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function plainTextResponse(string $content): array
    {
        return [
            'id' => 'resp-test-text',
            'model' => 'test-model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 12, 'total_tokens' => 22],
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
            ]],
        ];
    }
}

class QueueLlmClient implements LlmClientInterface
{
    /** @var array<int,array<string,mixed>> */
    private array $responses;

    /**
     * @param array<int,array<string,mixed>> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function createChatCompletion(array $payload): array
    {
        if ($this->responses === []) {
            throw new \RuntimeException('No queued LLM responses left.');
        }

        return array_shift($this->responses);
    }
}
