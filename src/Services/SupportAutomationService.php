<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\SupportAssigneeProfile;
use CarbonTrack\Models\SupportRoutingSetting;
use CarbonTrack\Models\SupportTicketAutomationRule;
use CarbonTrack\Models\SupportTicketTag;
use CarbonTrack\Models\SupportTicketTagAssignment;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;

class SupportAutomationService
{
    private const VALID_COLORS = ['slate', 'emerald', 'sky', 'amber', 'rose', 'violet'];

    public function __construct(
        private PDO $db,
        private LoggerInterface $logger,
        private AuditLogService $auditLogService,
        private ErrorLogService $errorLogService,
        private UserProfileViewService $userProfileViewService
    ) {
    }

    public function listAssignableUsers(): array
    {
        $stmt = $this->db->query("
            SELECT
                u.id,
                u.uuid,
                u.username,
                u.email,
                u.role,
                u.is_admin,
                u.status,
                u.school_id,
                s.name AS school_name,
                u.region_code,
                u.group_id,
                u.lastlgn,
                u.created_at,
                u.updated_at,
                COALESCE(p.level, CASE WHEN u.is_admin = 1 OR u.role = 'admin' THEN 5 WHEN u.role = 'support' THEN 2 ELSE 1 END) AS routing_level,
                COALESCE(p.skills_json, '[]') AS skills_json,
                COALESCE(p.languages_json, '[]') AS languages_json,
                COALESCE(p.max_active_tickets, 10) AS max_active_tickets,
                COALESCE(p.is_auto_assignable, 1) AS is_auto_assignable,
                COALESCE(p.weight_overrides_json, '{}') AS weight_overrides_json,
                COALESCE(p.status, CASE WHEN u.status = 'active' THEN 'active' ELSE 'offline' END) AS routing_status,
                COALESCE(feedback.avg_rating, 3.5) AS avg_feedback_rating,
                COALESCE(feedback.rating_count, 0) AS rating_count,
                SUM(CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_total_count,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN t.status = 'waiting_user' THEN 1 ELSE 0 END) AS waiting_user_count,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_count
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN support_assignee_profiles p ON p.user_id = u.id
            LEFT JOIN support_tickets t ON t.assigned_to = u.id
            LEFT JOIN (
                SELECT rated_user_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
                FROM support_ticket_feedback
                GROUP BY rated_user_id
            ) feedback ON feedback.rated_user_id = u.id
            WHERE u.deleted_at IS NULL
              AND (u.is_admin = 1 OR u.role IN ('support', 'admin'))
            GROUP BY
                u.id,
                u.uuid,
                u.username,
                u.email,
                u.role,
                u.is_admin,
                u.status,
                u.school_id,
                s.name,
                u.region_code,
                u.group_id,
                u.lastlgn,
                u.created_at,
                u.updated_at,
                p.level,
                p.skills_json,
                p.languages_json,
                p.max_active_tickets,
                p.is_auto_assignable,
                p.weight_overrides_json,
                p.status,
                feedback.avg_rating,
                feedback.rating_count
            ORDER BY u.is_admin DESC, COALESCE(u.username, u.email, '') ASC, u.id ASC
        ");

        return array_map(fn (array $row): array => $this->formatAssignableUser($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getAssignableUserDetail(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.uuid,
                u.username,
                u.email,
                u.role,
                u.is_admin,
                u.status,
                u.school_id,
                s.name AS school_name,
                u.region_code,
                u.group_id,
                u.lastlgn,
                u.created_at,
                u.updated_at,
                u.admin_notes,
                COALESCE(p.level, CASE WHEN u.is_admin = 1 OR u.role = 'admin' THEN 5 WHEN u.role = 'support' THEN 2 ELSE 1 END) AS routing_level,
                COALESCE(p.skills_json, '[]') AS skills_json,
                COALESCE(p.languages_json, '[]') AS languages_json,
                COALESCE(p.max_active_tickets, 10) AS max_active_tickets,
                COALESCE(p.is_auto_assignable, 1) AS is_auto_assignable,
                COALESCE(p.weight_overrides_json, '{}') AS weight_overrides_json,
                COALESCE(p.status, CASE WHEN u.status = 'active' THEN 'active' ELSE 'offline' END) AS routing_status,
                COALESCE(feedback.avg_rating, 3.5) AS avg_feedback_rating,
                COALESCE(feedback.rating_count, 0) AS rating_count,
                SUM(CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_total_count,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN t.status = 'waiting_user' THEN 1 ELSE 0 END) AS waiting_user_count,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_count
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN support_assignee_profiles p ON p.user_id = u.id
            LEFT JOIN support_tickets t ON t.assigned_to = u.id
            LEFT JOIN (
                SELECT rated_user_id, AVG(rating) AS avg_rating, COUNT(*) AS rating_count
                FROM support_ticket_feedback
                GROUP BY rated_user_id
            ) feedback ON feedback.rated_user_id = u.id
            WHERE u.id = :id
              AND u.deleted_at IS NULL
              AND (u.is_admin = 1 OR u.role IN ('support', 'admin'))
            GROUP BY
                u.id,
                u.uuid,
                u.username,
                u.email,
                u.role,
                u.is_admin,
                u.status,
                u.school_id,
                s.name,
                u.region_code,
                u.group_id,
                u.lastlgn,
                u.created_at,
                u.updated_at,
                u.admin_notes,
                p.level,
                p.skills_json,
                p.languages_json,
                p.max_active_tickets,
                p.is_auto_assignable,
                p.weight_overrides_json,
                p.status,
                feedback.avg_rating,
                feedback.rating_count
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $detail = $this->formatAssignableUser($row);
        $detail['admin_notes'] = $row['admin_notes'] ?? null;
        $detail['recent_tickets'] = $this->recentTicketsForAssignee($userId);
        $detail['routing_profile'] = [
            'user_id' => $detail['id'],
            'level' => (int) ($row['routing_level'] ?? 1),
            'skills' => $this->decodeJsonList($row['skills_json'] ?? null),
            'languages' => $this->decodeJsonList($row['languages_json'] ?? null),
            'max_active_tickets' => (int) ($row['max_active_tickets'] ?? 10),
            'is_auto_assignable' => !empty($row['is_auto_assignable']),
            'weight_overrides' => $this->decodeJsonObject($row['weight_overrides_json'] ?? null) ?? [],
            'status' => $row['routing_status'] ?? 'active',
            'avg_feedback_rating' => round((float) ($row['avg_feedback_rating'] ?? 3.5), 2),
            'rating_count' => (int) ($row['rating_count'] ?? 0),
        ];

        return $detail;
    }

    public function getRoutingSettings(): array
    {
        $row = $this->findRoutingSettingsRow();
        return $this->formatRoutingSettings($row ?? []);
    }

    public function saveRoutingSettings(array $actor, array $payload): array
    {
        $existing = $this->findRoutingSettingsRow();
        $weights = $this->normalizeJsonObject($payload['weights'] ?? ($this->decodeJsonObject($existing['weights_json'] ?? null) ?? []));
        $fallback = $this->normalizeJsonObject($payload['fallback'] ?? ($this->decodeJsonObject($existing['fallback_json'] ?? null) ?? []));
        $defaults = $this->normalizeJsonObject($payload['defaults'] ?? ($this->decodeJsonObject($existing['defaults_json'] ?? null) ?? []));
        $data = [
            'ai_enabled' => array_key_exists('ai_enabled', $payload) ? (bool) $payload['ai_enabled'] : (bool) ($existing['ai_enabled'] ?? true),
            'ai_timeout_ms' => max(1000, (int) ($payload['ai_timeout_ms'] ?? ($existing['ai_timeout_ms'] ?? 12000))),
            'due_soon_minutes' => max(1, (int) ($payload['due_soon_minutes'] ?? ($existing['due_soon_minutes'] ?? 30))),
            'weights_json' => json_encode($weights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'fallback_json' => json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'defaults_json' => json_encode($defaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $this->now(),
        ];

        if ($existing !== null) {
            $settings = SupportRoutingSetting::find((int) $existing['id']);
            $settings?->fill($data);
            $settings?->save();
            $result = $this->findRoutingSettingsRow();
            $action = 'support_routing_settings_updated';
        } else {
            $settings = SupportRoutingSetting::create($data + ['created_at' => $this->now()]);
            $result = $this->findRoutingSettingsRow((int) ($settings->id ?? 0));
            $action = 'support_routing_settings_created';
        }

        $formatted = $this->formatRoutingSettings($result ?? []);
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => !empty($actor['is_admin']) ? 'admin' : 'support',
            'affected_table' => 'support_routing_settings',
            'affected_id' => (int) ($formatted['id'] ?? 0),
            'status' => 'success',
            'new_data' => $formatted,
        ]);

        return $formatted;
    }

    public function getAssigneeRoutingProfile(int $userId): ?array
    {
        $detail = $this->getAssignableUserDetail($userId);
        if ($detail === null) {
            return null;
        }

        return $detail['routing_profile'] ?? null;
    }

    public function saveAssigneeRoutingProfile(array $actor, int $userId, array $payload): array
    {
        $assignee = $this->getAssignableUserDetail($userId);
        if ($assignee === null) {
            throw new \RuntimeException('Support assignee not found');
        }

        $existing = $this->findAssigneeProfileRow($userId);
        $data = [
            'user_id' => $userId,
            'level' => max(1, min(5, (int) ($payload['level'] ?? ($existing['level'] ?? ($assignee['routing_profile']['level'] ?? 1))))),
            'skills_json' => json_encode($this->normalizeStringList($payload['skills'] ?? $this->decodeJsonList($existing['skills_json'] ?? null)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'languages_json' => json_encode($this->normalizeStringList($payload['languages'] ?? $this->decodeJsonList($existing['languages_json'] ?? null)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'max_active_tickets' => max(1, (int) ($payload['max_active_tickets'] ?? ($existing['max_active_tickets'] ?? 10))),
            'is_auto_assignable' => array_key_exists('is_auto_assignable', $payload) ? (bool) $payload['is_auto_assignable'] : (bool) ($existing['is_auto_assignable'] ?? true),
            'weight_overrides_json' => json_encode($this->normalizeJsonObject($payload['weight_overrides'] ?? ($this->decodeJsonObject($existing['weight_overrides_json'] ?? null) ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $this->normalizeProfileStatus($payload['status'] ?? ($existing['status'] ?? 'active')),
            'updated_at' => $this->now(),
        ];

        if ($existing !== null) {
            $profile = SupportAssigneeProfile::find((int) $existing['id']);
            $profile?->fill($data);
            $profile?->save();
            $action = 'support_assignee_profile_updated';
        } else {
            SupportAssigneeProfile::create($data + ['created_at' => $this->now()]);
            $action = 'support_assignee_profile_created';
        }

        $detail = $this->getAssignableUserDetail($userId);
        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => !empty($actor['is_admin']) ? 'admin' : 'support',
            'affected_table' => 'support_assignee_profiles',
            'affected_id' => $userId,
            'status' => 'success',
            'new_data' => $detail['routing_profile'] ?? null,
        ]);

        return $detail ?? [];
    }

    public function listTags(): array
    {
        $stmt = $this->db->query("
            SELECT
                t.*,
                COUNT(DISTINCT sta.ticket_id) AS ticket_count
            FROM support_ticket_tags t
            LEFT JOIN support_ticket_tag_assignments sta ON sta.tag_id = t.id
            GROUP BY t.id
            ORDER BY t.is_active DESC, t.name ASC, t.id ASC
        ");

        return array_map(fn (array $row): array => $this->formatTag($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function saveTag(array $actor, array $payload, ?int $tagId = null): array
    {
        $existing = $tagId ? $this->findTagRow($tagId) : null;
        if ($tagId && $existing === null) {
            throw new \RuntimeException('Tag not found');
        }

        $name = $this->requireString($payload['name'] ?? null, 'name');
        $slug = $this->normalizeSlug($payload['slug'] ?? $name);
        $color = $this->normalizeColor($payload['color'] ?? ($existing['color'] ?? 'emerald'));
        $description = $this->nullableString($payload['description'] ?? null);
        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : (bool) ($existing['is_active'] ?? true);

        $duplicateStmt = $this->db->prepare('SELECT id FROM support_ticket_tags WHERE slug = :slug AND (:tag_id_null IS NULL OR id <> :tag_id_compare) LIMIT 1');
        $duplicateStmt->execute([
            'slug' => $slug,
            'tag_id_null' => $tagId,
            'tag_id_compare' => $tagId,
        ]);
        if ($duplicateStmt->fetchColumn()) {
            throw new \InvalidArgumentException('Tag slug already exists');
        }

        $now = $this->now();
        if ($existing) {
            $tag = SupportTicketTag::find($tagId);
            $tag->fill([
                'slug' => $slug,
                'name' => $name,
                'color' => $color,
                'description' => $description,
                'is_active' => $isActive,
                'updated_at' => $now,
            ]);
            $tag->save();
            $result = $this->findTagRow($tagId);
            $action = 'support_tag_updated';
        } else {
            $tag = SupportTicketTag::create([
                'slug' => $slug,
                'name' => $name,
                'color' => $color,
                'description' => $description,
                'is_active' => $isActive,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->findTagRow((int) $tag->id);
            $action = 'support_tag_created';
        }

        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => !empty($actor['is_admin']) ? 'admin' : 'support',
            'affected_table' => 'support_ticket_tags',
            'affected_id' => (int) ($result['id'] ?? 0),
            'status' => 'success',
            'new_data' => $result,
        ]);

        return $this->formatTag($result ?: []);
    }

    public function listRules(): array
    {
        $stmt = $this->db->query("
            SELECT
                r.*,
                assignee.username AS assignee_username,
                assignee.email AS assignee_email
            FROM support_ticket_automation_rules r
            LEFT JOIN users assignee ON assignee.id = r.assign_to
            ORDER BY r.sort_order ASC, r.id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn (array $row): array => $this->formatRule($row), $rows);
    }

    public function saveRule(array $actor, array $payload, ?int $ruleId = null): array
    {
        $existing = $ruleId ? $this->findRuleRow($ruleId) : null;
        if ($ruleId && $existing === null) {
            throw new \RuntimeException('Rule not found');
        }

        $name = $this->requireString($payload['name'] ?? null, 'name');
        $description = $this->nullableString($payload['description'] ?? null);
        $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : (bool) ($existing['is_active'] ?? true);
        $sortOrder = isset($payload['sort_order']) ? (int) $payload['sort_order'] : (int) ($existing['sort_order'] ?? 0);
        $matchCategory = $this->nullableString($payload['match_category'] ?? ($existing['match_category'] ?? null));
        $matchPriority = $this->nullableString($payload['match_priority'] ?? ($existing['match_priority'] ?? null));
        $weekdays = $this->normalizeWeekdays($payload['match_weekdays'] ?? $this->decodeJsonList($existing['match_weekdays'] ?? null));
        $timeStart = $this->normalizeTime($payload['match_time_start'] ?? ($existing['match_time_start'] ?? null));
        $timeEnd = $this->normalizeTime($payload['match_time_end'] ?? ($existing['match_time_end'] ?? null));
        $timezone = $this->normalizeTimezone($payload['timezone'] ?? ($existing['timezone'] ?? 'Asia/Shanghai'));
        $assignTo = $this->normalizeAssignableUser($payload['assign_to'] ?? ($existing['assign_to'] ?? null));
        $scoreBoost = isset($payload['score_boost']) ? round((float) $payload['score_boost'], 2) : (float) ($existing['score_boost'] ?? ($assignTo ? 20 : 0));
        $requiredAgentLevel = $this->normalizeRequiredAgentLevel($payload['required_agent_level'] ?? ($existing['required_agent_level'] ?? null));
        $skillHints = $this->normalizeStringList($payload['skill_hints'] ?? $this->decodeJsonList($existing['skill_hints_json'] ?? null));
        $tagIds = $this->normalizeTagIds($payload['tag_ids'] ?? $this->decodeJsonList($existing['add_tag_ids'] ?? null));

        $now = $this->now();
        $data = [
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'match_category' => $matchCategory,
            'match_priority' => $matchPriority,
            'match_weekdays' => $weekdays === [] ? null : json_encode($weekdays),
            'match_time_start' => $timeStart,
            'match_time_end' => $timeEnd,
            'timezone' => $timezone,
            'assign_to' => $assignTo,
            'score_boost' => $scoreBoost,
            'required_agent_level' => $requiredAgentLevel,
            'skill_hints_json' => $skillHints === [] ? null : json_encode($skillHints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'add_tag_ids' => $tagIds === [] ? null : json_encode($tagIds),
            'stop_processing' => false,
            'updated_at' => $now,
        ];

        if ($existing) {
            $rule = SupportTicketAutomationRule::find($ruleId);
            $rule->fill($data);
            $rule->save();
            $result = $this->findRuleRow($ruleId);
            $action = 'support_rule_updated';
        } else {
            $rule = SupportTicketAutomationRule::create($data + [
                'trigger_count' => 0,
                'last_triggered_at' => null,
                'created_at' => $now,
            ]);
            $result = $this->findRuleRow((int) $rule->id);
            $action = 'support_rule_created';
        }

        $this->auditLogService->log([
            'user_id' => (int) ($actor['id'] ?? 0),
            'action' => $action,
            'operation_category' => 'support',
            'actor_type' => !empty($actor['is_admin']) ? 'admin' : 'support',
            'affected_table' => 'support_ticket_automation_rules',
            'affected_id' => (int) ($result['id'] ?? 0),
            'status' => 'success',
            'new_data' => $result,
        ]);

        return $this->formatRule($result ?: []);
    }

    public function getReports(array $query = []): array
    {
        $days = max(7, min(90, (int) ($query['days'] ?? 14)));

        return [
            'summary' => $this->summaryMetrics(),
            'timeline' => $this->createdTimeline($days),
            'by_status' => $this->breakdown('status'),
            'by_category' => $this->breakdown('category'),
            'by_priority' => $this->breakdown('priority'),
            'by_assignee' => $this->assigneeBreakdown(),
            'by_agent_level' => $this->agentLevelBreakdown(),
            'by_tag' => $this->tagBreakdown(),
            'rule_hits' => $this->ruleHitBreakdown(),
            'routing_outcomes' => $this->routingOutcomeBreakdown(),
        ];
    }

    public function applyRulesToTicket(int $ticketId, ?array $ticket = null, string $trigger = 'created'): array
    {
        $ticketRow = $ticket ?? $this->findTicketRow($ticketId);
        if ($ticketRow === null) {
            throw new \RuntimeException('Ticket not found');
        }

        $rulesStmt = $this->db->query("
            SELECT
                r.*,
                assignee.username AS assignee_username,
                assignee.email AS assignee_email
            FROM support_ticket_automation_rules r
            LEFT JOIN users assignee ON assignee.id = r.assign_to
            WHERE r.is_active = 1
            ORDER BY r.sort_order ASC, r.id ASC
        ");
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ticketTags = $this->getTagsForTicketIds([$ticketId]);
        $applied = [];

        foreach ($rules as $rule) {
            if (!$this->ruleMatchesTicket($rule, $ticketRow)) {
                continue;
            }

            $appliedTagIds = [];
            $ruleTagIds = $this->decodeJsonList($rule['add_tag_ids'] ?? null);
            foreach ($ruleTagIds as $tagId) {
                $tagId = (int) $tagId;
                if ($tagId <= 0 || isset($ticketTags[$ticketId][$tagId])) {
                    continue;
                }
                $tagRow = $this->findTagRow($tagId);
                if ($tagRow === null || empty($tagRow['is_active'])) {
                    continue;
                }
                SupportTicketTagAssignment::create([
                    'ticket_id' => $ticketId,
                    'tag_id' => $tagId,
                    'source_type' => 'rule',
                    'rule_id' => (int) $rule['id'],
                    'created_at' => $this->now(),
                ]);
                $ticketTags[$ticketId][$tagId] = $this->formatTag($tagRow);
                $appliedTagIds[] = $tagId;
            }

            if ($appliedTagIds !== [] || !empty($rule['assign_to']) || (float) ($rule['score_boost'] ?? 0) !== 0.0) {
                $this->touchRuleMetrics((int) $rule['id']);
                $applied[] = [
                    'rule_id' => (int) $rule['id'],
                    'rule_name' => (string) $rule['name'],
                    'assigned_to' => !empty($rule['assign_to']) ? (int) $rule['assign_to'] : null,
                    'score_boost' => round((float) ($rule['score_boost'] ?? 0), 2),
                    'required_agent_level' => isset($rule['required_agent_level']) ? (int) $rule['required_agent_level'] : null,
                    'tag_ids' => $appliedTagIds,
                ];

                $this->auditLogService->log([
                    'user_id' => null,
                    'action' => 'support_rule_applied',
                    'operation_category' => 'support',
                    'actor_type' => 'system',
                    'affected_table' => 'support_tickets',
                    'affected_id' => $ticketId,
                    'status' => 'success',
                    'data' => [
                        'trigger' => $trigger,
                        'rule_id' => (int) $rule['id'],
                        'assigned_to' => !empty($rule['assign_to']) ? (int) $rule['assign_to'] : null,
                        'score_boost' => round((float) ($rule['score_boost'] ?? 0), 2),
                        'tag_ids' => $appliedTagIds,
                    ],
                ]);
            }
        }

        return [
            'assigned_to' => isset($ticketRow['assigned_to']) ? (int) $ticketRow['assigned_to'] : null,
            'assignment_source' => $ticketRow['assignment_source'] ?? null,
            'assigned_rule_id' => isset($ticketRow['assigned_rule_id']) ? (int) $ticketRow['assigned_rule_id'] : null,
            'applied_rules' => $applied,
            'tags' => array_values($ticketTags[$ticketId] ?? []),
        ];
    }

    public function getTagsForTicket(int $ticketId): array
    {
        return array_values($this->getTagsForTicketIds([$ticketId])[$ticketId] ?? []);
    }

    public function getTagsForTicketIds(array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds), fn (int $id): bool => $id > 0)));
        if ($ticketIds === []) {
            return [];
        }

        $sql = '
            SELECT
                sta.ticket_id,
                sta.source_type,
                sta.rule_id,
                t.*
            FROM support_ticket_tag_assignments sta
            INNER JOIN support_ticket_tags t ON t.id = sta.tag_id
            WHERE sta.ticket_id IN (' . implode(',', array_fill(0, count($ticketIds), '?')) . ')
            ORDER BY t.name ASC, t.id ASC
        ';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ticketIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tagsByTicket = [];
        foreach ($rows as $row) {
            $ticketId = (int) $row['ticket_id'];
            $tag = $this->formatTag($row);
            $tag['source_type'] = $row['source_type'] ?? 'rule';
            $tag['rule_id'] = isset($row['rule_id']) ? (int) $row['rule_id'] : null;
            $tagsByTicket[$ticketId][$tag['id']] = $tag;
        }

        return $tagsByTicket;
    }

    private function summaryMetrics(): array
    {
        $stmt = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN status = 'waiting_user' THEN 1 ELSE 0 END) AS waiting_user_count,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned_count,
                SUM(CASE WHEN assignment_source = 'smart' THEN 1 ELSE 0 END) AS smart_assigned_count,
                SUM(CASE WHEN assignment_source = 'manual' THEN 1 ELSE 0 END) AS manual_assigned_count,
                SUM(CASE WHEN sla_status IN ('breached', 'escalated') THEN 1 ELSE 0 END) AS sla_breach_count
            FROM support_tickets
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $avgResolutionHours = null;
        $resolutionStmt = $this->db->query('SELECT created_at, resolved_at FROM support_tickets WHERE resolved_at IS NOT NULL');
        $resolutionRows = $resolutionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($resolutionRows !== []) {
            $hours = [];
            foreach ($resolutionRows as $resolutionRow) {
                try {
                    $createdAt = new DateTimeImmutable((string) $resolutionRow['created_at']);
                    $resolvedAt = new DateTimeImmutable((string) $resolutionRow['resolved_at']);
                    $hours[] = max(0, ($resolvedAt->getTimestamp() - $createdAt->getTimestamp()) / 3600);
                } catch (\Throwable) {
                    continue;
                }
            }
            if ($hours !== []) {
                $avgResolutionHours = round(array_sum($hours) / count($hours), 1);
            }
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'open' => (int) ($row['open_count'] ?? 0),
            'in_progress' => (int) ($row['in_progress_count'] ?? 0),
            'waiting_user' => (int) ($row['waiting_user_count'] ?? 0),
            'resolved' => (int) ($row['resolved_count'] ?? 0),
            'closed' => (int) ($row['closed_count'] ?? 0),
            'unassigned' => (int) ($row['unassigned_count'] ?? 0),
            'smart_assignment_count' => (int) ($row['smart_assigned_count'] ?? 0),
            'manual_assigned' => (int) ($row['manual_assigned_count'] ?? 0),
            'sla_breach_count' => (int) ($row['sla_breach_count'] ?? 0),
            'avg_resolution_hours' => $avgResolutionHours,
        ];
    }

    private function createdTimeline(int $days): array
    {
        $startDate = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days')->format('Y-m-d 00:00:00');
        $stmt = $this->db->prepare("
            SELECT DATE(created_at) AS date_key, COUNT(*) AS ticket_count
            FROM support_tickets
            WHERE created_at >= :start_date
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC
        ");
        $stmt->execute(['start_date' => $startDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['date_key']] = (int) $row['ticket_count'];
        }

        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = (new DateTimeImmutable('today'))->modify('-' . ($days - $i - 1) . ' days')->format('Y-m-d');
            $result[] = ['date' => $date, 'count' => (int) ($indexed[$date] ?? 0)];
        }

        return $result;
    }

    private function breakdown(string $field): array
    {
        $stmt = $this->db->query("
            SELECT {$field} AS bucket, COUNT(*) AS ticket_count
            FROM support_tickets
            GROUP BY {$field}
            ORDER BY ticket_count DESC, {$field} ASC
        ");

        return array_map(fn (array $row): array => [
            'key' => (string) ($row['bucket'] ?? ''),
            'count' => (int) ($row['ticket_count'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function assigneeBreakdown(): array
    {
        $stmt = $this->db->query("
            SELECT
                COALESCE(u.username, 'unassigned') AS label,
                t.assigned_to,
                COUNT(*) AS ticket_count
            FROM support_tickets t
            LEFT JOIN users u ON u.id = t.assigned_to
            GROUP BY t.assigned_to, COALESCE(u.username, 'unassigned')
            ORDER BY ticket_count DESC, label ASC
        ");

        return array_map(fn (array $row): array => [
            'id' => isset($row['assigned_to']) ? (int) $row['assigned_to'] : null,
            'label' => (string) ($row['label'] ?? 'unassigned'),
            'count' => (int) ($row['ticket_count'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function agentLevelBreakdown(): array
    {
        $stmt = $this->db->query("
            SELECT
                COALESCE(p.level, 0) AS level_bucket,
                COUNT(*) AS assignee_count
            FROM support_assignee_profiles p
            INNER JOIN users u ON u.id = p.user_id
            WHERE u.deleted_at IS NULL
              AND (u.is_admin = 1 OR u.role IN ('support', 'admin'))
            GROUP BY COALESCE(p.level, 0)
            ORDER BY level_bucket ASC
        ");

        return array_map(static fn (array $row): array => [
            'level' => (int) ($row['level_bucket'] ?? 0),
            'count' => (int) ($row['assignee_count'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function tagBreakdown(): array
    {
        $stmt = $this->db->query("
            SELECT
                t.id,
                t.slug,
                t.name,
                t.color,
                COUNT(sta.ticket_id) AS ticket_count
            FROM support_ticket_tags t
            LEFT JOIN support_ticket_tag_assignments sta ON sta.tag_id = t.id
            GROUP BY t.id
            ORDER BY ticket_count DESC, t.name ASC
        ");

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'color' => (string) $row['color'],
            'count' => (int) ($row['ticket_count'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function ruleHitBreakdown(): array
    {
        $stmt = $this->db->query("
            SELECT id, name, trigger_count, last_triggered_at, is_active
            FROM support_ticket_automation_rules
            ORDER BY trigger_count DESC, sort_order ASC, id ASC
        ");

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'trigger_count' => (int) ($row['trigger_count'] ?? 0),
            'last_triggered_at' => $row['last_triggered_at'] ?? null,
            'is_active' => !empty($row['is_active']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function routingOutcomeBreakdown(): array
    {
        $stmt = $this->db->query("
            SELECT
                COALESCE(`trigger`, 'unknown') AS trigger_bucket,
                COUNT(*) AS run_count,
                SUM(CASE WHEN winner_user_id IS NULL THEN 1 ELSE 0 END) AS no_winner_count,
                SUM(CASE WHEN used_ai = 1 THEN 1 ELSE 0 END) AS used_ai_count
            FROM support_ticket_routing_runs
            GROUP BY COALESCE(`trigger`, 'unknown')
            ORDER BY run_count DESC, trigger_bucket ASC
        ");

        return array_map(static fn (array $row): array => [
            'trigger' => (string) ($row['trigger_bucket'] ?? 'unknown'),
            'count' => (int) ($row['run_count'] ?? 0),
            'no_winner_count' => (int) ($row['no_winner_count'] ?? 0),
            'used_ai_count' => (int) ($row['used_ai_count'] ?? 0),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function touchRuleMetrics(int $ruleId): void
    {
        $stmt = $this->db->prepare("
            UPDATE support_ticket_automation_rules
            SET trigger_count = trigger_count + 1,
                last_triggered_at = :last_triggered_at,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $now = $this->now();
        $stmt->execute([
            'last_triggered_at' => $now,
            'updated_at' => $now,
            'id' => $ruleId,
        ]);
    }

    private function findTicketRow(int $ticketId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_tickets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findTagRow(int $tagId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.*,
                COUNT(DISTINCT sta.ticket_id) AS ticket_count
            FROM support_ticket_tags t
            LEFT JOIN support_ticket_tag_assignments sta ON sta.tag_id = t.id
            WHERE t.id = :id
            GROUP BY t.id
            LIMIT 1
        ");
        $stmt->execute(['id' => $tagId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findRuleRow(int $ruleId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                r.*,
                assignee.username AS assignee_username,
                assignee.email AS assignee_email
            FROM support_ticket_automation_rules r
            LEFT JOIN users assignee ON assignee.id = r.assign_to
            WHERE r.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $ruleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findRoutingSettingsRow(?int $settingsId = null): ?array
    {
        $sql = 'SELECT * FROM support_routing_settings';
        $params = [];
        if ($settingsId !== null && $settingsId > 0) {
            $sql .= ' WHERE id = :id';
            $params['id'] = $settingsId;
        }
        $sql .= ' ORDER BY id ASC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findAssigneeProfileRow(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM support_assignee_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function ruleMatchesTicket(array $rule, array $ticket): bool
    {
        if (!empty($rule['match_category']) && (string) $rule['match_category'] !== (string) ($ticket['category'] ?? '')) {
            return false;
        }
        if (!empty($rule['match_priority']) && (string) $rule['match_priority'] !== (string) ($ticket['priority'] ?? '')) {
            return false;
        }

        $timezone = $this->normalizeTimezone($rule['timezone'] ?? 'Asia/Shanghai');
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $weekday = strtolower($now->format('D'));
        $ruleWeekdays = $this->decodeJsonList($rule['match_weekdays'] ?? null);
        if ($ruleWeekdays !== [] && !in_array($weekday, $ruleWeekdays, true)) {
            return false;
        }

        $timeStart = $rule['match_time_start'] ?? null;
        $timeEnd = $rule['match_time_end'] ?? null;
        if ($timeStart || $timeEnd) {
            $currentTime = $now->format('H:i');
            if (!$this->timeWindowMatches($currentTime, $timeStart, $timeEnd)) {
                return false;
            }
        }

        return true;
    }

    private function timeWindowMatches(string $current, ?string $start, ?string $end): bool
    {
        if (!$start || !$end) {
            return true;
        }
        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }
        return $current >= $start || $current <= $end;
    }

    private function formatTag(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'color' => (string) ($row['color'] ?? 'emerald'),
            'description' => $row['description'] ?? null,
            'is_active' => !empty($row['is_active']),
            'ticket_count' => (int) ($row['ticket_count'] ?? 0),
            'source_type' => $row['source_type'] ?? null,
            'rule_id' => isset($row['rule_id']) ? (int) $row['rule_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function formatAssignableUser(array $row): array
    {
        $profileFields = $this->userProfileViewService->buildProfileFields($row);
        $legacyDisplayFields = $this->userProfileViewService->buildLegacyDisplayFields($row, $profileFields);
        $maxActiveTickets = max(1, (int) ($row['max_active_tickets'] ?? 10));
        $activeCount = (int) (($row['open_count'] ?? 0) + ($row['in_progress_count'] ?? 0) + ($row['waiting_user_count'] ?? 0));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => $row['uuid'] ?? null,
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
            'role' => !empty($row['is_admin']) ? 'admin' : strtolower((string) ($row['role'] ?? 'support')),
            'status' => $row['status'] ?? null,
            'school_id' => $profileFields['school_id'] ?? (isset($row['school_id']) ? (int) $row['school_id'] : null),
            'school' => $legacyDisplayFields['school'] ?? null,
            'region_code' => $profileFields['region_code'] ?? ($row['region_code'] ?? null),
            'location' => $legacyDisplayFields['location'] ?? null,
            'group_id' => isset($row['group_id']) ? (int) $row['group_id'] : null,
            'last_login_at' => $row['lastlgn'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'assigned_total_count' => (int) ($row['assigned_total_count'] ?? 0),
            'open_count' => (int) ($row['open_count'] ?? 0),
            'in_progress_count' => (int) ($row['in_progress_count'] ?? 0),
            'waiting_user_count' => (int) ($row['waiting_user_count'] ?? 0),
            'resolved_count' => (int) ($row['resolved_count'] ?? 0),
            'closed_count' => (int) ($row['closed_count'] ?? 0),
            'routing_level' => (int) ($row['routing_level'] ?? 1),
            'skills' => $this->decodeJsonList($row['skills_json'] ?? null),
            'languages' => $this->decodeJsonList($row['languages_json'] ?? null),
            'max_active_tickets' => $maxActiveTickets,
            'is_auto_assignable' => !empty($row['is_auto_assignable']),
            'routing_status' => $row['routing_status'] ?? 'active',
            'avg_feedback_rating' => round((float) ($row['avg_feedback_rating'] ?? 3.5), 2),
            'rating_count' => (int) ($row['rating_count'] ?? 0),
            'available_capacity' => max(0, $maxActiveTickets - $activeCount),
            'weight_overrides' => $this->decodeJsonObject($row['weight_overrides_json'] ?? null) ?? [],
        ];
    }

    private function recentTicketsForAssignee(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, subject, status, priority, last_replied_at, updated_at, created_at
            FROM support_tickets
            WHERE assigned_to = :assigned_to
            ORDER BY COALESCE(last_replied_at, updated_at, created_at) DESC, id DESC
            LIMIT 10
        ");
        $stmt->execute(['assigned_to' => $userId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'subject' => (string) ($row['subject'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'priority' => (string) ($row['priority'] ?? ''),
            'last_replied_at' => $row['last_replied_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function formatRule(array $row): array
    {
        $tagIds = array_map('intval', $this->decodeJsonList($row['add_tag_ids'] ?? null));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'is_active' => !empty($row['is_active']),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'match_category' => $row['match_category'] ?? null,
            'match_priority' => $row['match_priority'] ?? null,
            'match_weekdays' => $this->decodeJsonList($row['match_weekdays'] ?? null),
            'match_time_start' => $row['match_time_start'] ?? null,
            'match_time_end' => $row['match_time_end'] ?? null,
            'timezone' => $row['timezone'] ?? 'Asia/Shanghai',
            'assign_to' => isset($row['assign_to']) ? (int) $row['assign_to'] : null,
            'assign_user' => !empty($row['assign_to']) ? [
                'id' => (int) $row['assign_to'],
                'username' => $row['assignee_username'] ?? null,
                'email' => $row['assignee_email'] ?? null,
            ] : null,
            'score_boost' => round((float) ($row['score_boost'] ?? 0), 2),
            'required_agent_level' => isset($row['required_agent_level']) && $row['required_agent_level'] !== null ? (int) $row['required_agent_level'] : null,
            'skill_hints' => $this->decodeJsonList($row['skill_hints_json'] ?? null),
            'tag_ids' => $tagIds,
            'tags' => $tagIds === [] ? [] : $this->loadTagsByIds($tagIds),
            'trigger_count' => (int) ($row['trigger_count'] ?? 0),
            'last_triggered_at' => $row['last_triggered_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function loadTagsByIds(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), fn (int $id): bool => $id > 0)));
        if ($tagIds === []) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT * FROM support_ticket_tags WHERE id IN (' . implode(',', array_fill(0, count($tagIds), '?')) . ') ORDER BY name ASC');
        $stmt->execute($tagIds);
        return array_map(fn (array $row): array => $this->formatTag($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function normalizeAssignableUser(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $userId = (int) $value;
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT id
            FROM users
            WHERE id = :id
              AND deleted_at IS NULL
              AND (is_admin = 1 OR role IN ('support', 'admin'))
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        if (!$stmt->fetchColumn()) {
            throw new \InvalidArgumentException('Assigned user must be support or admin');
        }
        return $userId;
    }

    private function normalizeTagIds(mixed $value): array
    {
        $ids = [];
        foreach ($this->decodeList($value) as $item) {
            $tagId = (int) $item;
            if ($tagId <= 0) {
                continue;
            }
            if ($this->findTagRow($tagId) === null) {
                throw new \InvalidArgumentException('Invalid tag id: ' . $tagId);
            }
            $ids[] = $tagId;
        }
        return array_values(array_unique($ids));
    }

    private function normalizeWeekdays(mixed $value): array
    {
        $valid = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $days = [];
        foreach ($this->decodeList($value) as $item) {
            $day = strtolower(trim((string) $item));
            if ($day === '') {
                continue;
            }
            if (!in_array($day, $valid, true)) {
                throw new \InvalidArgumentException('Invalid weekday: ' . $day);
            }
            $days[] = $day;
        }
        return array_values(array_unique($days));
    }

    private function normalizeTime(mixed $value): ?string
    {
        $time = $this->nullableString($value);
        if ($time === null) {
            return null;
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new \InvalidArgumentException('Invalid time value');
        }
        return $time;
    }

    private function normalizeRequiredAgentLevel(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $level = (int) $value;
        if ($level < 1 || $level > 5) {
            throw new \InvalidArgumentException('required_agent_level must be between 1 and 5');
        }
        return $level;
    }

    private function normalizeProfileStatus(mixed $value): string
    {
        $status = strtolower($this->nullableString($value) ?? 'active');
        if (!in_array($status, ['active', 'backup', 'offline'], true)) {
            throw new \InvalidArgumentException('Invalid routing profile status');
        }
        return $status;
    }

    private function normalizeTimezone(mixed $value): string
    {
        $timezone = $this->nullableString($value) ?? 'Asia/Shanghai';
        try {
            new DateTimeZone($timezone);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid timezone');
        }
        return $timezone;
    }

    private function normalizeColor(mixed $value): string
    {
        $color = strtolower($this->nullableString($value) ?? 'emerald');
        if (!in_array($color, self::VALID_COLORS, true)) {
            throw new \InvalidArgumentException('Invalid tag color');
        }
        return $color;
    }

    private function normalizeSlug(mixed $value): string
    {
        $slug = strtolower(trim((string) $value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new \InvalidArgumentException('Tag slug is required');
        }
        return substr($slug, 0, 64);
    }

    private function normalizeStringList(mixed $value): array
    {
        $items = [];
        foreach ($this->decodeList($value) as $item) {
            $normalized = trim((string) $item);
            if ($normalized === '') {
                continue;
            }
            $items[] = $normalized;
        }
        return array_values(array_unique($items));
    }

    private function normalizeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function requireString(mixed $value, string $field): string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            throw new \InvalidArgumentException(sprintf('%s is required', $field));
        }
        return $string;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function decodeJsonList(?string $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decodeJsonObject(?string $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function formatRoutingSettings(array $row): array
    {
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

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'ai_enabled' => array_key_exists('ai_enabled', $row) ? !empty($row['ai_enabled']) : true,
            'ai_timeout_ms' => (int) ($row['ai_timeout_ms'] ?? 12000),
            'due_soon_minutes' => (int) ($row['due_soon_minutes'] ?? 30),
            'weights' => array_replace($weights, $this->decodeJsonObject($row['weights_json'] ?? null) ?? []),
            'fallback' => array_replace($fallback, $this->decodeJsonObject($row['fallback_json'] ?? null) ?? []),
            'defaults' => array_replace($defaults, $this->decodeJsonObject($row['defaults_json'] ?? null) ?? []),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
        }
        return [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
