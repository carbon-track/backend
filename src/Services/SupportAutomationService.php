<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

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
                SUM(CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_total_count,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN t.status = 'waiting_user' THEN 1 ELSE 0 END) AS waiting_user_count,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_count
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN support_tickets t ON t.assigned_to = u.id
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
                u.updated_at
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
                SUM(CASE WHEN t.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_total_count,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN t.status = 'waiting_user' THEN 1 ELSE 0 END) AS waiting_user_count,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_count
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN support_tickets t ON t.assigned_to = u.id
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
                u.admin_notes
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

        return $detail;
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
        $tagIds = $this->normalizeTagIds($payload['tag_ids'] ?? $this->decodeJsonList($existing['add_tag_ids'] ?? null));
        $stopProcessing = array_key_exists('stop_processing', $payload) ? (bool) $payload['stop_processing'] : (bool) ($existing['stop_processing'] ?? false);

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
            'add_tag_ids' => $tagIds === [] ? null : json_encode($tagIds),
            'stop_processing' => $stopProcessing,
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
            'by_tag' => $this->tagBreakdown(),
            'rule_hits' => $this->ruleHitBreakdown(),
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
        $assignedTo = isset($ticketRow['assigned_to']) ? (int) $ticketRow['assigned_to'] : null;
        $assignmentSource = $ticketRow['assignment_source'] ?? null;
        $assignedRuleId = isset($ticketRow['assigned_rule_id']) ? (int) $ticketRow['assigned_rule_id'] : null;
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

            $appliedAssignment = false;
            if (!$assignedTo && !empty($rule['assign_to'])) {
                $assignedTo = (int) $rule['assign_to'];
                $assignmentSource = 'rule';
                $assignedRuleId = (int) $rule['id'];
                $this->db->prepare('UPDATE support_tickets SET assigned_to = :assigned_to, assignment_source = :assignment_source, assigned_rule_id = :assigned_rule_id, updated_at = :updated_at WHERE id = :id')
                    ->execute([
                        'assigned_to' => $assignedTo,
                        'assignment_source' => $assignmentSource,
                        'assigned_rule_id' => $assignedRuleId,
                        'updated_at' => $this->now(),
                        'id' => $ticketId,
                    ]);
                $appliedAssignment = true;
            }

            if ($appliedAssignment || $appliedTagIds !== []) {
                $this->touchRuleMetrics((int) $rule['id']);
                $applied[] = [
                    'rule_id' => (int) $rule['id'],
                    'rule_name' => (string) $rule['name'],
                    'assigned_to' => $appliedAssignment ? $assignedTo : null,
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
                        'assigned_to' => $appliedAssignment ? $assignedTo : null,
                        'tag_ids' => $appliedTagIds,
                    ],
                ]);

                if (!empty($rule['stop_processing'])) {
                    break;
                }
            }
        }

        return [
            'assigned_to' => $assignedTo,
            'assignment_source' => $assignmentSource,
            'assigned_rule_id' => $assignedRuleId,
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
                SUM(CASE WHEN assignment_source = 'rule' THEN 1 ELSE 0 END) AS auto_assigned_count,
                SUM(CASE WHEN assignment_source = 'manual' THEN 1 ELSE 0 END) AS manual_assigned_count
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
            'auto_assigned' => (int) ($row['auto_assigned_count'] ?? 0),
            'manual_assigned' => (int) ($row['manual_assigned_count'] ?? 0),
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
            'tag_ids' => $tagIds,
            'tags' => $tagIds === [] ? [] : $this->loadTagsByIds($tagIds),
            'stop_processing' => !empty($row['stop_processing']),
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
