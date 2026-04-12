<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\SupportTicketRoutingRun;
use CarbonTrack\Models\SupportTicketTagAssignment;
use CarbonTrack\Support\SyntheticRequestFactory;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;

class SupportRoutingEngineService
{
    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService,
        private SupportRoutingTriageService $triageService,
        private ?MessageService $messageService = null,
        private ?EmailService $emailService = null
    ) {
    }

    public function routeTicket(int $ticketId, string $trigger = 'created', array $options = []): ?array
    {
        $ticket = $this->loadTicket($ticketId);
        if ($ticket === null) {
            throw new \RuntimeException('Ticket not found');
        }

        $force = (bool) ($options['force'] ?? false);
        if (!$force && !empty($ticket['assignment_locked'])) {
            return $this->buildLockedResult($ticket, $trigger);
        }

        $settings = $this->loadRoutingSettings();
        $groupRouting = $this->resolveGroupRouting($ticket, $settings['defaults']);
        $this->ensureDeadlineFields($ticket, $groupRouting);
        $ticket = $this->loadTicket($ticketId) ?? $ticket;

        $triageResult = $this->triageService->triage($ticket, [
            'ai_enabled' => $settings['ai_enabled'],
            'group_routing' => $groupRouting,
            'message_body' => $this->loadFirstMessageBody($ticketId),
            'log_context' => [
                'request_id' => $options['request_id'] ?? null,
                'actor_type' => 'system',
                'source' => '/support/routing/engine',
            ],
        ]);

        $matchedRules = [];
        $matchedRuleIds = [];
        $tagIds = [];
        $skillHints = $triageResult['triage']['suggested_skills'] ?? [];
        $requiredLevel = max(1, (int) ($groupRouting['min_agent_level'] ?? 1), (int) ($triageResult['triage']['required_agent_level'] ?? 1));

        foreach ($this->loadActiveRules() as $rule) {
            if (!$this->ruleMatchesTicket($rule, $ticket)) {
                continue;
            }

            $matchedRules[] = $rule;
            $matchedRuleIds[] = (int) $rule['id'];
            $tagIds = array_merge($tagIds, $this->decodeJsonList($rule['add_tag_ids'] ?? null));
            $skillHints = array_merge($skillHints, $this->decodeJsonList($rule['skill_hints_json'] ?? null));
            if (($rule['required_agent_level'] ?? null) !== null) {
                $requiredLevel = max($requiredLevel, (int) $rule['required_agent_level']);
            }
        }

        $skillHints = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string) $value), $skillHints), static fn (string $value): bool => $value !== '')));
        $scoredCandidates = [];
        foreach ($this->loadCandidateRows() as $candidate) {
            $score = $this->scoreCandidate($candidate, $ticket, $groupRouting, $settings['weights'], $triageResult['triage'], $matchedRules, $requiredLevel, $skillHints);
            if ($score !== null) {
                $scoredCandidates[] = $score;
            }
        }

        usort($scoredCandidates, function (array $left, array $right): int {
            if ($left['total_score'] === $right['total_score']) {
                if ($left['available_capacity'] === $right['available_capacity']) {
                    if ($left['avg_feedback_rating'] === $right['avg_feedback_rating']) {
                        return $left['candidate']['id'] <=> $right['candidate']['id'];
                    }
                    return $right['avg_feedback_rating'] <=> $left['avg_feedback_rating'];
                }
                return $right['available_capacity'] <=> $left['available_capacity'];
            }

            return $right['total_score'] <=> $left['total_score'];
        });

        $winner = $scoredCandidates[0] ?? null;
        $winnerId = $winner['candidate']['id'] ?? null;
        $winnerScore = $winner['total_score'] ?? null;
        $topFactors = $this->normalizeTopFactors($winner['breakdown'] ?? []);
        $summary = [
            'locked' => false,
            'used_ai' => (bool) $triageResult['used_ai'],
            'fallback_reason' => $triageResult['fallback_reason'],
            'matched_rule_ids' => $matchedRuleIds,
            'required_agent_level' => $requiredLevel,
            'suggested_skills' => $skillHints,
            'winner_score' => $winnerScore,
            'winner_label' => $winner !== null ? ($winner['candidate']['username'] ?? $winner['candidate']['email'] ?? ('User #' . (int) ($winner['candidate']['id'] ?? 0))) : null,
            'top_factors' => $topFactors,
        ];

        try {
            $this->db->beginTransaction();
            $this->applyMatchedTags($ticketId, $matchedRules, $tagIds);
            foreach ($matchedRuleIds as $ruleId) {
                $this->touchRuleMetrics($ruleId);
            }

            $run = SupportTicketRoutingRun::create([
                'ticket_id' => $ticketId,
                'trigger' => $trigger,
                'used_ai' => !empty($triageResult['used_ai']),
                'fallback_reason' => $triageResult['fallback_reason'],
                'triage_json' => $this->encodeJson($triageResult['triage']),
                'matched_rule_ids_json' => $this->encodeJson($matchedRuleIds),
                'candidate_scores_json' => $this->encodeJson(array_map(fn (array $candidate): array => [
                    'candidate' => $candidate['candidate'],
                    'candidate_id' => (int) ($candidate['candidate']['id'] ?? 0),
                    'total_score' => round((float) ($candidate['total_score'] ?? 0), 2),
                    'breakdown' => $candidate['breakdown'],
                    'available_capacity' => $candidate['available_capacity'],
                    'avg_feedback_rating' => $candidate['avg_feedback_rating'],
                ], $scoredCandidates)),
                'winner_user_id' => $winnerId !== null ? (int) $winnerId : null,
                'winner_score' => $winnerScore !== null ? round((float) $winnerScore, 2) : null,
                'summary_json' => $this->encodeJson($summary),
            ]);

            $updates = [
                'assignment_locked' => 0,
                'last_routing_run_id' => (int) $run->id,
                'updated_at' => $this->now(),
            ];
            if ($winnerId !== null) {
                $updates['assigned_to'] = (int) $winnerId;
                $updates['assignment_source'] = 'smart';
                $updates['assigned_rule_id'] = $this->resolvePrimaryRuleId($matchedRules, (int) $winnerId);
            }
            $this->updateTicket($ticketId, $updates);
            $this->db->commit();

            if ($winnerId !== null) {
                $assignedUser = $this->loadUser((int) $winnerId);
                if ($assignedUser !== null) {
                    $this->notifyAssignee(
                        $assignedUser,
                        sprintf('Ticket #%d assigned to you', $ticketId),
                        sprintf("Smart routing assigned ticket #%d.\nSubject: %s\nReason: %s", $ticketId, (string) ($ticket['subject'] ?? ''), (string) ($triageResult['triage']['summary'] ?? '')),
                        $ticketId
                    );
                }
            }

            $this->auditLogService->logSystemEvent('support_ticket_routed', 'support_routing', [
                'status' => 'success',
                'request_method' => 'SYSTEM',
                'endpoint' => '/support/routing/engine',
                'request_data' => [
                    'ticket_id' => $ticketId,
                    'trigger' => $trigger,
                    'winner_user_id' => $winnerId,
                    'matched_rule_ids' => $matchedRuleIds,
                    'fallback_reason' => $triageResult['fallback_reason'],
                ],
            ]);

            return [
                'assigned_to' => $winnerId !== null ? (int) $winnerId : null,
                'assignment_source' => $winnerId !== null ? 'smart' : null,
                'matched_rule_ids' => $matchedRuleIds,
                'routing_run_id' => (int) $run->id,
                'summary' => $summary,
            ];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError($exception, ['ticket_id' => $ticketId, 'trigger' => $trigger]);
            throw $exception;
        }
    }

    public function runSlaSweep(): array
    {
        $stmt = $this->db->query("
            SELECT id
            FROM support_tickets
            WHERE status IN ('open', 'in_progress', 'waiting_user')
            ORDER BY id ASC
        ");
        $ticketIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $processed = 0;
        $breached = 0;
        $rerouted = 0;
        $now = $this->now();

        foreach ($ticketIds as $ticketId) {
            $ticket = $this->loadTicket($ticketId);
            if ($ticket === null) {
                continue;
            }

            $firstResponseBreached = empty($ticket['first_support_response_at']) && !empty($ticket['first_response_due_at']) && (string) $ticket['first_response_due_at'] < $now;
            $resolutionBreached = !empty($ticket['resolution_due_at']) && (string) $ticket['resolution_due_at'] < $now;
            if (!$firstResponseBreached && !$resolutionBreached) {
                continue;
            }

            $currentSlaStatus = strtolower((string) ($ticket['sla_status'] ?? 'pending'));
            if (in_array($currentSlaStatus, ['breached', 'escalated'], true)) {
                continue;
            }

            $processed++;
            $breached++;
            $updates = ['sla_status' => 'breached', 'updated_at' => $now];
            if (empty($ticket['assignment_locked'])) {
                $updates['sla_status'] = 'escalated';
                $updates['escalation_level'] = (int) ($ticket['escalation_level'] ?? 0) + 1;
                $this->updateTicket($ticketId, $updates);
                try {
                    $this->routeTicket($ticketId, 'sla_breach', ['force' => true]);
                    $rerouted++;
                } catch (\Throwable $exception) {
                    $this->logger->warning('Support SLA reroute failed', [
                        'ticket_id' => $ticketId,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->logError($exception, [
                        'ticket_id' => $ticketId,
                        'trigger' => 'sla_breach',
                    ]);
                }
            } else {
                $this->updateTicket($ticketId, $updates);
            }
        }

        $this->auditLogService->logSystemEvent('support_ticket_sla_sweep_completed', 'support_routing', [
            'status' => 'success',
            'request_method' => 'CLI',
            'endpoint' => '/jobs/support/sla-sweep',
            'request_data' => ['processed' => $processed, 'breached' => $breached, 'rerouted' => $rerouted],
        ]);

        return ['processed' => $processed, 'breached' => $breached, 'rerouted' => $rerouted];
    }

    public function getRoutingSummaryForTicket(int $ticketId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT summary_json, id
            FROM support_ticket_routing_runs
            WHERE ticket_id = :ticket_id
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute(['ticket_id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $summary = $this->decodeJsonObject($row['summary_json'] ?? null) ?? [];
        $summary['top_factors'] = $this->normalizeTopFactors($summary['top_factors'] ?? []);
        $summary['last_run_id'] = (int) ($row['id'] ?? 0);
        return $summary;
    }

    public function buildSlaSummaryForTicket(array $ticket, ?array $routingSettings = null): array
    {
        $settings = $routingSettings ?? $this->loadRoutingSettings();
        $dueSoonMinutes = max(1, (int) ($settings['due_soon_minutes'] ?? 30));

        $firstResponse = $this->buildMilestoneSummary(
            $ticket['first_response_due_at'] ?? null,
            $ticket['first_support_response_at'] ?? null,
            (string) ($ticket['sla_status'] ?? 'pending'),
            $dueSoonMinutes
        );

        $resolvedAt = $ticket['resolved_at'] ?? $ticket['closed_at'] ?? null;
        $resolution = $this->buildMilestoneSummary(
            $ticket['resolution_due_at'] ?? null,
            $resolvedAt,
            (string) ($ticket['sla_status'] ?? 'pending'),
            $dueSoonMinutes
        );

        $activeTarget = empty($ticket['first_support_response_at']) ? 'first_response' : 'resolution';
        $activeSummary = $activeTarget === 'first_response' ? $firstResponse : $resolution;
        $displayState = $this->resolveDisplayState($ticket, $activeSummary['state'] ?? 'pending');

        return [
            'due_soon_minutes' => $dueSoonMinutes,
            'display_state' => $displayState,
            'active_target' => $activeTarget,
            'active_due_at' => $activeSummary['due_at'] ?? null,
            'active_minutes_delta' => $activeSummary['minutes_delta'] ?? null,
            'first_response' => $firstResponse,
            'resolution' => $resolution,
        ];
    }

    public function getSlaSettingsSnapshot(): array
    {
        return $this->loadRoutingSettings();
    }

    public function getRoutingRunsForTicket(int $ticketId, int $limit = 10): array
    {
        $stmt = $this->db->prepare('
            SELECT *
            FROM support_ticket_routing_runs
            WHERE ticket_id = :ticket_id
            ORDER BY id DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':ticket_id', $ticketId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $row): array {
            $candidateScores = json_decode((string) ($row['candidate_scores_json'] ?? '[]'), true);
            $summary = $this->decodeJsonObject($row['summary_json'] ?? null) ?? [];
            $summary['top_factors'] = $this->normalizeTopFactors($summary['top_factors'] ?? []);
            $winnerUserId = isset($row['winner_user_id']) ? (int) $row['winner_user_id'] : null;

            return [
                'id' => (int) ($row['id'] ?? 0),
                'ticket_id' => (int) ($row['ticket_id'] ?? 0),
                'trigger' => (string) ($row['trigger'] ?? 'unknown'),
                'used_ai' => !empty($row['used_ai']),
                'fallback_reason' => $row['fallback_reason'] ?? null,
                'triage' => $this->decodeJsonObject($row['triage_json'] ?? null) ?? [],
                'matched_rule_ids' => array_map('intval', $this->decodeJsonList($row['matched_rule_ids_json'] ?? null)),
                'candidate_scores' => is_array($candidateScores) ? $candidateScores : [],
                'winner_user_id' => $winnerUserId,
                'winner_score' => isset($row['winner_score']) ? (float) $row['winner_score'] : null,
                'summary' => $summary,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function buildLockedResult(array $ticket, string $trigger): array
    {
        return [
            'assigned_to' => isset($ticket['assigned_to']) ? (int) $ticket['assigned_to'] : null,
            'assignment_source' => $ticket['assignment_source'] ?? null,
            'matched_rule_ids' => [],
            'routing_run_id' => isset($ticket['last_routing_run_id']) ? (int) $ticket['last_routing_run_id'] : null,
            'summary' => [
                'locked' => true,
                'used_ai' => false,
                'fallback_reason' => 'assignment_locked',
                'matched_rule_ids' => [],
                'required_agent_level' => null,
                'suggested_skills' => [],
                'winner_score' => null,
                'top_factors' => [],
                'trigger' => $trigger,
            ],
        ];
    }

    private function loadTicket(int $ticketId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.*,
                requester.username AS requester_username,
                requester.email AS requester_email,
                requester.group_id AS requester_group_id,
                requester.quota_override AS requester_quota_override,
                requester.role AS requester_role
            FROM support_tickets t
            INNER JOIN users requester ON requester.id = t.user_id
            WHERE t.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadFirstMessageBody(int $ticketId): string
    {
        $stmt = $this->db->prepare('
            SELECT body
            FROM support_ticket_messages
            WHERE ticket_id = :ticket_id
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute(['ticket_id' => $ticketId]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    private function loadRoutingSettings(): array
    {
        $stmt = $this->db->query('SELECT * FROM support_routing_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $defaults = [
            'first_response_minutes' => 240,
            'resolution_minutes' => 1440,
            'routing_weight' => 1.0,
            'min_agent_level' => 1,
            'overdue_boost' => 1.0,
            'tier_label' => 'standard',
        ];
        $weights = [
            'group_weight' => 15,
            'priority_weight' => 18,
            'severity_weight' => 24,
            'escalation_weight' => 10,
            'rule_weight' => 20,
            'skill_weight' => 16,
            'level_weight' => 10,
            'feedback_weight' => 8,
            'overdue_weight' => 18,
            'load_penalty_weight' => 22,
        ];
        $fallback = [
            'use_priority_as_severity' => true,
            'default_feedback_rating' => 3.5,
        ];

        if (!$row) {
            return [
                'id' => null,
                'ai_enabled' => false,
                'ai_timeout_ms' => 12000,
                'due_soon_minutes' => 30,
                'weights' => $weights,
                'fallback' => $fallback,
                'defaults' => $defaults,
            ];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'ai_enabled' => !empty($row['ai_enabled']),
            'ai_timeout_ms' => (int) ($row['ai_timeout_ms'] ?? 12000),
            'due_soon_minutes' => (int) ($row['due_soon_minutes'] ?? 30),
            'weights' => array_replace($weights, $this->decodeJsonObject($row['weights_json'] ?? null) ?? []),
            'fallback' => array_replace($fallback, $this->decodeJsonObject($row['fallback_json'] ?? null) ?? []),
            'defaults' => array_replace($defaults, $this->decodeJsonObject($row['defaults_json'] ?? null) ?? []),
        ];
    }

    private function resolveGroupRouting(array $ticket, array $defaults): array
    {
        $groupId = isset($ticket['requester_group_id']) ? (int) $ticket['requester_group_id'] : 0;
        $groupRouting = $defaults;

        if ($groupId > 0) {
            $stmt = $this->db->prepare('SELECT config FROM user_groups WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $groupId]);
            $config = $this->decodeJsonObject($stmt->fetchColumn() ?: null) ?? [];
            $supportRouting = is_array($config['support_routing'] ?? null) ? $config['support_routing'] : [];
            $groupRouting = array_replace($groupRouting, $supportRouting);
        }

        $requesterOverride = $this->decodeJsonObject($ticket['requester_quota_override'] ?? null) ?? [];
        $supportRoutingOverride = is_array($requesterOverride['support_routing'] ?? null) ? $requesterOverride['support_routing'] : [];

        return array_replace($groupRouting, $supportRoutingOverride);
    }

    private function buildMilestoneSummary(?string $dueAt, ?string $completedAt, string $slaStatus, int $dueSoonMinutes): array
    {
        if (!$dueAt) {
            return [
                'due_at' => null,
                'completed_at' => $completedAt,
                'minutes_delta' => null,
                'state' => 'not_configured',
            ];
        }

        $now = new DateTimeImmutable($this->now(), new DateTimeZone('Asia/Shanghai'));
        $due = new DateTimeImmutable($dueAt, new DateTimeZone('Asia/Shanghai'));
        $minutesDelta = (int) floor(($due->getTimestamp() - $now->getTimestamp()) / 60);

        if ($completedAt) {
            return [
                'due_at' => $dueAt,
                'completed_at' => $completedAt,
                'minutes_delta' => $minutesDelta,
                'state' => 'met',
            ];
        }

        $state = 'pending';
        if ($minutesDelta < 0) {
            $state = $slaStatus === 'escalated' ? 'escalated' : 'breached';
        } elseif ($minutesDelta <= $dueSoonMinutes) {
            $state = 'due_soon';
        }

        return [
            'due_at' => $dueAt,
            'completed_at' => null,
            'minutes_delta' => $minutesDelta,
            'state' => $state,
        ];
    }

    private function resolveDisplayState(array $ticket, string $activeState): string
    {
        if (in_array((string) ($ticket['status'] ?? ''), ['resolved', 'closed'], true) || (string) ($ticket['sla_status'] ?? '') === 'resolved') {
            return 'resolved';
        }

        return match ($activeState) {
            'escalated' => 'escalated',
            'breached' => 'breached',
            'due_soon' => 'due_soon',
            default => 'pending',
        };
    }

    private function normalizeTopFactors(mixed $value): array
    {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                return array_values(array_map(
                    static fn ($item): string => trim((string) $item),
                    array_filter(
                        $value,
                        static fn ($item): bool => is_scalar($item) && trim((string) $item) !== ''
                    )
                ));
            }

            $pairs = [];
            foreach ($value as $key => $score) {
                if (!is_numeric($score)) {
                    continue;
                }
                $pairs[(string) $key] = (float) $score;
            }

            uasort($pairs, static fn (float $left, float $right): int => abs($right) <=> abs($left));

            return array_slice($pairs, 0, 4, true);
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return [];
    }

    private function ensureDeadlineFields(array $ticket, array $groupRouting): void
    {
        if (!empty($ticket['first_response_due_at']) && !empty($ticket['resolution_due_at'])) {
            return;
        }

        $createdAt = $ticket['created_at'] ?? $this->now();
        $base = new DateTimeImmutable((string) $createdAt, new DateTimeZone('Asia/Shanghai'));
        $firstResponseDueAt = $base->modify('+' . max(1, (int) ($groupRouting['first_response_minutes'] ?? 240)) . ' minutes');
        $resolutionDueAt = $base->modify('+' . max(1, (int) ($groupRouting['resolution_minutes'] ?? 1440)) . ' minutes');

        $updates = ['updated_at' => $this->now()];
        if (empty($ticket['first_response_due_at'])) {
            $updates['first_response_due_at'] = $firstResponseDueAt->format('Y-m-d H:i:s');
        }
        if (empty($ticket['resolution_due_at'])) {
            $updates['resolution_due_at'] = $resolutionDueAt->format('Y-m-d H:i:s');
        }
        if (empty($ticket['sla_status'])) {
            $updates['sla_status'] = 'pending';
        }

        $this->updateTicket((int) $ticket['id'], $updates);
    }

    private function loadActiveRules(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM support_ticket_automation_rules
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadCandidateRows(): array
    {
        $stmt = $this->db->query("
            SELECT
                u.id,
                u.username,
                u.email,
                u.role,
                u.is_admin,
                u.status AS user_status,
                COALESCE(p.level, 1) AS level,
                COALESCE(p.skills_json, '[]') AS skills_json,
                COALESCE(p.languages_json, '[]') AS languages_json,
                COALESCE(p.max_active_tickets, 10) AS max_active_tickets,
                COALESCE(p.is_auto_assignable, 1) AS is_auto_assignable,
                p.weight_overrides_json,
                COALESCE(p.status, 'active') AS profile_status,
                COALESCE(active.active_count, 0) AS active_count,
                COALESCE(feedback.avg_rating, 3.5) AS avg_feedback_rating,
                COALESCE(feedback.rating_count, 0) AS rating_count
            FROM users u
            LEFT JOIN support_assignee_profiles p ON p.user_id = u.id
            LEFT JOIN (
                SELECT assigned_to, COUNT(*) AS active_count
                FROM support_tickets
                WHERE status IN ('open', 'in_progress', 'waiting_user')
                  AND assigned_to IS NOT NULL
                GROUP BY assigned_to
            ) active ON active.assigned_to = u.id
            LEFT JOIN (
                SELECT
                    rated_user_id,
                    AVG(rating) AS avg_rating,
                    COUNT(*) AS rating_count
                FROM (
                    SELECT rated_user_id, rating
                    FROM support_ticket_feedback
                    ORDER BY id DESC
                    LIMIT 500
                ) recent
                GROUP BY rated_user_id
            ) feedback ON feedback.rated_user_id = u.id
            WHERE u.deleted_at IS NULL
              AND (u.is_admin = 1 OR u.role IN ('support', 'admin'))
            ORDER BY u.id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scoreCandidate(
        array $candidate,
        array $ticket,
        array $groupRouting,
        array $weights,
        array $triage,
        array $matchedRules,
        int $requiredLevel,
        array $skillHints
    ): ?array {
        $userStatus = strtolower((string) ($candidate['user_status'] ?? ''));
        $profileStatus = strtolower((string) ($candidate['profile_status'] ?? ''));
        if ($userStatus !== 'active' || $profileStatus !== 'active' || empty($candidate['is_auto_assignable'])) {
            return null;
        }

        $candidateLevel = max(1, (int) ($candidate['level'] ?? 1));
        if ($candidateLevel < $requiredLevel) {
            return null;
        }

        $maxActiveTickets = max(1, (int) ($candidate['max_active_tickets'] ?? 10));
        $activeCount = max(0, (int) ($candidate['active_count'] ?? 0));
        $loadRatio = $activeCount / $maxActiveTickets;
        if ($loadRatio >= 1.0) {
            return null;
        }

        $priorityValue = [
            'low' => 0.25,
            'normal' => 0.5,
            'high' => 0.75,
            'urgent' => 1.0,
        ][strtolower((string) ($ticket['priority'] ?? 'normal'))] ?? 0.5;
        $severityValue = [
            'low' => 0.25,
            'medium' => 0.5,
            'high' => 0.75,
            'critical' => 1.0,
        ][strtolower((string) ($triage['severity'] ?? 'medium'))] ?? 0.5;
        $riskValue = [
            'low' => 0.2,
            'medium' => 0.6,
            'high' => 1.0,
        ][strtolower((string) ($triage['escalation_risk'] ?? 'medium'))] ?? 0.6;

        $groupScore = ((float) ($groupRouting['routing_weight'] ?? 1.0)) * (float) ($weights['group_weight'] ?? 15);
        $priorityScore = $priorityValue * (float) ($weights['priority_weight'] ?? 18);
        $severityScore = $severityValue * (float) ($weights['severity_weight'] ?? 24);
        $escalationScore = ($riskValue + ((int) ($ticket['escalation_level'] ?? 0) * 0.25)) * (float) ($weights['escalation_weight'] ?? 10);

        $ruleBoost = 0.0;
        $assigneeOverride = 0.0;
        foreach ($matchedRules as $rule) {
            $boost = (float) ($rule['score_boost'] ?? 0);
            $ruleBoost += $boost;
            if (!empty($rule['assign_to']) && (int) $rule['assign_to'] === (int) $candidate['id']) {
                $assigneeOverride += $boost;
            }
        }
        $ruleScore = $ruleBoost * ((float) ($weights['rule_weight'] ?? 20) / 20.0);

        $candidateSkills = array_values(array_unique(array_filter(array_map(static fn ($value): string => strtolower(trim((string) $value)), $this->decodeJsonList($candidate['skills_json'] ?? null)), static fn (string $value): bool => $value !== '')));
        $normalizedSkillHints = array_values(array_unique(array_filter(array_map(static fn ($value): string => strtolower(trim((string) $value)), $skillHints), static fn (string $value): bool => $value !== '')));
        $skillMatches = array_intersect($candidateSkills, $normalizedSkillHints);
        $skillRatio = $normalizedSkillHints === [] ? 0.5 : (count($skillMatches) / count($normalizedSkillHints));
        $skillScore = $skillRatio * (float) ($weights['skill_weight'] ?? 16);

        $levelRatio = min(1.0, $candidateLevel / max(1, $requiredLevel));
        $levelScore = $levelRatio * (float) ($weights['level_weight'] ?? 10);

        $avgFeedback = max(1.0, min(5.0, (float) ($candidate['avg_feedback_rating'] ?? 3.5)));
        $feedbackScore = (($avgFeedback - 1.0) / 4.0) * (float) ($weights['feedback_weight'] ?? 8);
        $overdueScore = $this->ticketOverdueMultiplier($ticket, $groupRouting) * (float) ($weights['overdue_weight'] ?? 18);
        $loadPenalty = $loadRatio * (float) ($weights['load_penalty_weight'] ?? 22);
        $weightOverrides = $this->decodeJsonObject($candidate['weight_overrides_json'] ?? null) ?? [];
        $assigneeOverride += (float) ($weightOverrides['flat_boost'] ?? 0);

        $total = $groupScore
            + $priorityScore
            + $severityScore
            + $escalationScore
            + $ruleScore
            + $skillScore
            + $levelScore
            + $feedbackScore
            + $overdueScore
            - $loadPenalty
            + $assigneeOverride;

        return [
            'candidate' => [
                'id' => (int) ($candidate['id'] ?? 0),
                'username' => $candidate['username'] ?? null,
                'email' => $candidate['email'] ?? null,
            ],
            'total_score' => round($total, 2),
            'breakdown' => [
                'group' => round($groupScore, 2),
                'priority' => round($priorityScore, 2),
                'severity' => round($severityScore, 2),
                'escalation' => round($escalationScore, 2),
                'rule' => round($ruleScore, 2),
                'skill' => round($skillScore, 2),
                'level' => round($levelScore, 2),
                'feedback' => round($feedbackScore, 2),
                'overdue' => round($overdueScore, 2),
                'load_penalty' => round($loadPenalty, 2),
                'assignee_override' => round($assigneeOverride, 2),
            ],
            'available_capacity' => $maxActiveTickets - $activeCount,
            'avg_feedback_rating' => round($avgFeedback, 2),
        ];
    }

    private function ticketOverdueMultiplier(array $ticket, array $groupRouting): float
    {
        $now = $this->now();
        $boost = max(0.0, (float) ($groupRouting['overdue_boost'] ?? 1.0));
        $firstResponseOverdue = empty($ticket['first_support_response_at']) && !empty($ticket['first_response_due_at']) && (string) $ticket['first_response_due_at'] < $now;
        $resolutionOverdue = !empty($ticket['resolution_due_at']) && (string) $ticket['resolution_due_at'] < $now;

        if ($firstResponseOverdue || $resolutionOverdue) {
            return max(1.0, $boost);
        }

        return 0.0;
    }

    private function applyMatchedTags(int $ticketId, array $matchedRules, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return;
        }

        $existing = [];
        $stmt = $this->db->prepare('SELECT tag_id FROM support_ticket_tag_assignments WHERE ticket_id = :ticket_id');
        $stmt->execute(['ticket_id' => $ticketId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $tagId) {
            $existing[(int) $tagId] = true;
        }

        foreach ($matchedRules as $rule) {
            foreach (array_map('intval', $this->decodeJsonList($rule['add_tag_ids'] ?? null)) as $tagId) {
                if ($tagId <= 0 || isset($existing[$tagId])) {
                    continue;
                }

                SupportTicketTagAssignment::create([
                    'ticket_id' => $ticketId,
                    'tag_id' => $tagId,
                    'source_type' => 'rule',
                    'rule_id' => (int) $rule['id'],
                    'created_at' => $this->now(),
                ]);
                $existing[$tagId] = true;
            }
        }
    }

    private function touchRuleMetrics(int $ruleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE support_ticket_automation_rules
            SET trigger_count = trigger_count + 1,
                last_triggered_at = :timestamp,
                updated_at = :timestamp
            WHERE id = :id
        ");
        $stmt->execute([
            'timestamp' => $this->now(),
            'id' => $ruleId,
        ]);
    }

    private function ruleMatchesTicket(array $rule, array $ticket): bool
    {
        if (!empty($rule['match_category']) && (string) $rule['match_category'] !== (string) ($ticket['category'] ?? '')) {
            return false;
        }
        if (!empty($rule['match_priority']) && (string) $rule['match_priority'] !== (string) ($ticket['priority'] ?? '')) {
            return false;
        }

        $timezone = (string) ($rule['timezone'] ?? 'Asia/Shanghai');
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        } catch (\Throwable) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        }

        $weekdays = $this->decodeJsonList($rule['match_weekdays'] ?? null);
        if ($weekdays !== []) {
            $currentWeekday = strtolower(substr($now->format('D'), 0, 3));
            if (!in_array($currentWeekday, $weekdays, true)) {
                return false;
            }
        }

        $start = $rule['match_time_start'] ?? null;
        $end = $rule['match_time_end'] ?? null;
        if (($start || $end) && !$this->timeWindowMatches($now->format('H:i'), is_string($start) ? $start : null, is_string($end) ? $end : null)) {
            return false;
        }

        return true;
    }

    private function timeWindowMatches(string $current, ?string $start, ?string $end): bool
    {
        if ($start === null || $end === null) {
            return true;
        }
        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }
        return $current >= $start || $current <= $end;
    }

    private function resolvePrimaryRuleId(array $matchedRules, int $winnerUserId): ?int
    {
        $bestRuleId = null;
        $bestScore = -INF;
        foreach ($matchedRules as $rule) {
            $score = (float) ($rule['score_boost'] ?? 0);
            if (!empty($rule['assign_to']) && (int) $rule['assign_to'] === $winnerUserId) {
                $score += 1000;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRuleId = (int) $rule['id'];
            }
        }
        return $bestRuleId;
    }

    private const TICKET_UPDATE_ALLOWED_FIELDS = [
        'assigned_to',
        'assignment_source',
        'assigned_rule_id',
        'assignment_locked',
        'first_support_response_at',
        'first_response_due_at',
        'resolution_due_at',
        'sla_status',
        'escalation_level',
        'last_routing_run_id',
        'updated_at',
    ];

    private function updateTicket(int $ticketId, array $fields): void
    {
        $assignments = [];
        $params = ['id' => $ticketId];
        foreach ($fields as $field => $value) {
            if (!in_array($field, self::TICKET_UPDATE_ALLOWED_FIELDS, true)) {
                $this->logger->warning('SupportRoutingEngineService::updateTicket rejected disallowed field', [
                    'field' => $field,
                    'ticket_id' => $ticketId,
                ]);
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $field, $field);
            $params[$field] = $value;
        }
        if ($assignments === []) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE support_tickets SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    private function loadUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, username, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function notifyAssignee(array $user, string $subject, string $body, int $ticketId): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $messageSent = false;
        $emailSent = false;

        try {
            if ($this->messageService !== null && $userId > 0) {
                $this->messageService->sendSystemMessage(
                    $userId,
                    $subject,
                    $body,
                    'support_ticket',
                    'normal',
                    'support_ticket',
                    $ticketId,
                    false
                );
                $messageSent = true;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send smart assignment system notification', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
            $this->recordNotificationFailure($exception, 'support_routing_system_notification_failed', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'subject' => $subject,
            ]);
        }

        try {
            if ($this->emailService !== null && !empty($user['email'])) {
                $this->emailService->sendMessageNotification(
                    (string) $user['email'],
                    (string) ($user['username'] ?? $user['email']),
                    $subject,
                    $body,
                    NotificationPreferenceService::CATEGORY_SUPPORT,
                    'normal'
                );
                $emailSent = true;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send smart assignment email notification', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
            $this->recordNotificationFailure($exception, 'support_routing_email_notification_failed', [
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'subject' => $subject,
            ]);
        }

        $this->auditLogService->log([
            'user_id' => $userId > 0 ? $userId : null,
            'action' => 'support_routing_assignee_notified',
            'operation_category' => 'support',
            'actor_type' => 'system',
            'affected_table' => 'support_tickets',
            'affected_id' => $ticketId,
            'status' => $messageSent && $emailSent
                ? 'success'
                : (($messageSent || $emailSent) ? 'partial' : 'failed'),
            'data' => [
                'subject' => $subject,
                'message_sent' => $messageSent,
                'email_sent' => $emailSent,
            ],
        ]);
    }

    private function recordNotificationFailure(\Throwable $exception, string $action, array $context): void
    {
        $this->auditLogService->log([
            'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : null,
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => 'system',
            'affected_table' => 'support_tickets',
            'affected_id' => isset($context['ticket_id']) ? (int) $context['ticket_id'] : null,
            'status' => 'failed',
            'data' => $context + ['error' => $exception->getMessage()],
        ]);

        $this->logError($exception, $context);
    }

    private function decodeJsonList(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeJsonObject(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function encodeJson(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }

    private function logError(\Throwable $exception, array $context = []): void
    {
        try {
            $request = SyntheticRequestFactory::fromContext(
                '/support/routing/engine',
                'SYSTEM',
                null,
                [],
                $context,
                ['PHP_SAPI' => PHP_SAPI]
            );
            $this->errorLogService->logException($exception, $request, $context);
        } catch (\Throwable) {
            // ignore
        }
    }
}
