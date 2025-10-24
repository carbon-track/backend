<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

class StatisticsService
{
    private const MESSAGE_TREND_WINDOW_DAYS = 30;

    private DateTimeZone $timezone;

    public function __construct(
        private PDO $db,
        private ?string $cacheDir = null,
        private ?int $publicTtlSeconds = null,
        private ?int $adminTtlSeconds = null
    ) {
        $tzName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
        if (!$tzName) {
            $tzName = 'UTC';
        }
        $this->timezone = new DateTimeZone($tzName);
        if ($this->cacheDir === null) {
            $this->cacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        }
        $this->publicTtlSeconds = $this->validateTtl($this->publicTtlSeconds, (int)($_ENV['STATS_PUBLIC_CACHE_TTL'] ?? 600));
        $this->adminTtlSeconds = $this->validateTtl($this->adminTtlSeconds, (int)($_ENV['STATS_ADMIN_CACHE_TTL'] ?? 180));
    }

    public function getAdminStats(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache('admin', $this->adminTtlSeconds);
            if ($cached !== null) {
                return $cached['data'];
            }
        }

        $data = $this->computeAdminStats();
        $this->writeCache('admin', $data, $this->adminTtlSeconds);

        // Refresh public cache alongside admin stats so homepage stays in sync.
        $public = $this->buildPublicSummary($data);
        $this->writeCache('public', $public, $this->publicTtlSeconds);

        return $data;
    }

    public function getPublicStats(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache('public', $this->publicTtlSeconds);
            if ($cached !== null) {
                return $cached['data'];
            }
        }

        $adminData = $this->getAdminStats(true);
        $summary = $this->buildPublicSummary($adminData);
        $this->writeCache('public', $summary, $this->publicTtlSeconds);

        return $summary;
    }

    private function computeAdminStats(): array
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $thirtyDaysAgo = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $sevenDaysAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dateExpr = $driver === 'sqlite' ? "substr(created_at,1,10)" : "DATE(created_at)";

        $carbonDeletedCondition = $driver === 'sqlite' ? 'deleted_at IS NULL' : "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
        $carbonDeletedConditionAliasedCr = $driver === 'sqlite' ? 'cr.deleted_at IS NULL' : "(cr.deleted_at IS NULL OR cr.deleted_at = '0000-00-00 00:00:00')";
        $activityDeletedCondition = $driver === 'sqlite' ? 'deleted_at IS NULL' : "(deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";

        $stmtUser = $this->db->prepare("SELECT COUNT(*) AS total_users,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status IN ('inactive','suspended') THEN 1 ELSE 0 END) AS inactive_users,
                SUM(CASE WHEN created_at >= :d30 THEN 1 ELSE 0 END) AS new_users_30d
                FROM users WHERE deleted_at IS NULL");
        $stmtUser->execute([':d30' => $thirtyDaysAgo]);
        $userStatsRaw = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

        $transactionStatsRaw = $this->db->query("SELECT COUNT(*) AS total_transactions,
                SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_transactions,
                SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved_transactions,
                SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_transactions,
                COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN points ELSE 0 END), 0) AS total_points_awarded
                FROM points_transactions WHERE {$activityDeletedCondition}")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $txWindowStmt = $this->db->prepare("SELECT COUNT(*) AS total_transactions,
                COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN points ELSE 0 END), 0) AS total_points_awarded
                FROM points_transactions
                WHERE {$activityDeletedCondition} AND created_at >= :d7");
        $txWindowStmt->execute([':d7' => $sevenDaysAgo]);
        $txWindowRaw = $txWindowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $exchangeStatsRaw = $this->db->query("SELECT COUNT(*) AS total_exchanges,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_exchanges,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_exchanges,
                SUM(CASE WHEN status NOT IN ('pending','completed') THEN 1 ELSE 0 END) AS other_exchanges,
                COALESCE(SUM(points_used), 0) AS total_points_spent
                FROM point_exchanges WHERE {$activityDeletedCondition}")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $messageStatsRaw = $this->db->query("SELECT COUNT(*) AS total_messages,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_messages,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_messages
                FROM messages WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $messagePriorityRows = [];
        try {
            $prioritySql = "SELECT COALESCE(priority, 'normal') AS priority,
                    COUNT(*) AS total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read
                FROM messages
                WHERE deleted_at IS NULL
                GROUP BY COALESCE(priority, 'normal')";
            $priorityStmt = $this->db->query($prioritySql);
            if ($priorityStmt) {
                $messagePriorityRows = $priorityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (\Throwable $ignore) {
            $messagePriorityRows = [];
        }

        $messageTrendRows = [];
        $messageTrendStart = $now
            ->modify('-' . (self::MESSAGE_TREND_WINDOW_DAYS - 1) . ' days')
            ->setTime(0, 0, 0);
        try {
            $trendSql = "SELECT {$dateExpr} AS day_label,
                    COUNT(*) AS total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read
                FROM messages
                WHERE deleted_at IS NULL AND created_at >= :start_date
                GROUP BY {$dateExpr}
                ORDER BY {$dateExpr}";
            $trendStmt = $this->db->prepare($trendSql);
            if ($trendStmt) {
                $trendStmt->execute([':start_date' => $messageTrendStart->format('Y-m-d H:i:s')]);
                $messageTrendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (\Throwable $ignore) {
            $messageTrendRows = [];
        }

        $activityRecordStatsRaw = $this->db->query("SELECT COUNT(*) AS total_records,
                SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_records,
                SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_records
                FROM carbon_records WHERE {$carbonDeletedCondition}")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $activityCatalogStatsRaw = $this->db->query("SELECT COUNT(*) AS total_activities,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_activities,
                SUM(CASE WHEN is_active = 0 OR is_active IS NULL THEN 1 ELSE 0 END) AS inactive_activities
                FROM carbon_activities WHERE {$activityDeletedCondition}")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $carbonStatsRaw = $this->db->query("SELECT COUNT(*) AS total_records,
                SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_records,
                SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN LOWER(status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_records,
                COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN carbon_saved ELSE 0 END), 0) AS total_carbon_saved,
                COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN points_earned ELSE 0 END), 0) AS total_points_earned
                FROM carbon_records WHERE {$carbonDeletedCondition}")?->fetch(PDO::FETCH_ASSOC) ?: [];

        $carbonWindowStmt = $this->db->prepare("SELECT
                    COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN carbon_saved ELSE 0 END), 0) AS carbon_saved,
                    COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN points_earned ELSE 0 END), 0) AS points_earned,
                    SUM(CASE WHEN LOWER(status) = 'pending' THEN 1 ELSE 0 END) AS pending_records,
                    SUM(CASE WHEN LOWER(status) = 'approved' THEN 1 ELSE 0 END) AS approved_records
                FROM carbon_records
                WHERE {$carbonDeletedCondition} AND created_at >= :d7");
        $carbonWindowStmt->execute([':d7' => $sevenDaysAgo]);
        $carbonWindowRaw = $carbonWindowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $trendTransactions = [];
        try {
            $trendTxStmt = $this->db->prepare("SELECT {$dateExpr} AS date,
                        COUNT(*) AS transactions,
                        COALESCE(SUM(CASE WHEN LOWER(status) = 'approved' THEN points ELSE 0 END), 0) AS points_awarded
                    FROM points_transactions
                    WHERE {$activityDeletedCondition} AND created_at >= :d30
                    GROUP BY {$dateExpr}");
            $trendTxStmt->execute([':d30' => $thirtyDaysAgo]);
            $trendTransactions = $trendTxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $ignore) {
        }

        $trendCarbon = [];
        try {
            $trendCarbonStmt = $this->db->prepare("SELECT {$dateExpr} AS date,
                        COALESCE(SUM(carbon_saved), 0) AS carbon_saved,
                        COUNT(*) AS approved_records
                    FROM carbon_records
                    WHERE {$carbonDeletedCondition} AND created_at >= :d30 AND LOWER(status) = 'approved'
                    GROUP BY {$dateExpr}");
            $trendCarbonStmt->execute([':d30' => $thirtyDaysAgo]);
            $trendCarbon = $trendCarbonStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $ignore) {
        }

        $trendMap = [];
        for ($i = 29; $i >= 0; $i--) {
            $dateKey = $now->modify("-{$i} days")->format('Y-m-d');
            $trendMap[$dateKey] = [
                'date' => $dateKey,
                'transactions' => 0,
                'carbon_saved' => 0.0,
                'points_awarded' => 0.0,
                'approved_records' => 0,
            ];
        }

        foreach ($trendTransactions as $row) {
            $d = (string)($row['date'] ?? '');
            if ($d !== '' && isset($trendMap[$d])) {
                $trendMap[$d]['transactions'] = $this->toInt($row['transactions'] ?? 0);
                $trendMap[$d]['points_awarded'] = $this->toFloat($row['points_awarded'] ?? 0);
            }
        }

        foreach ($trendCarbon as $row) {
            $d = (string)($row['date'] ?? '');
            if ($d !== '' && isset($trendMap[$d])) {
                $trendMap[$d]['carbon_saved'] = $this->toFloat($row['carbon_saved'] ?? 0);
                $trendMap[$d]['approved_records'] = $this->toInt($row['approved_records'] ?? 0);
            }
        }

        $trendData = array_values($trendMap);
        $trendCount = count($trendData);
        $trendTotals = [
            'transactions' => 0,
            'carbon_saved' => 0.0,
            'points_awarded' => 0.0,
            'approved_records' => 0,
        ];
        foreach ($trendData as $entry) {
            $trendTotals['transactions'] += $entry['transactions'];
            $trendTotals['carbon_saved'] += $entry['carbon_saved'];
            $trendTotals['points_awarded'] += $entry['points_awarded'];
            $trendTotals['approved_records'] += $entry['approved_records'];
        }

        $last7 = $trendCount > 7 ? array_slice($trendData, -7) : $trendData;
        $prev7 = [];
        if ($trendCount > 7) {
            $prev7 = array_slice($trendData, max(0, $trendCount - 14), max(0, min(7, $trendCount - 7)));
        }

        $sumColumn = static function (array $rows, string $key): float {
            $total = 0.0;
            foreach ($rows as $row) {
                $value = $row[$key] ?? 0;
                $total += is_numeric($value) ? (float) $value : 0.0;
            }
            return $total;
        };

        $carbonLast7 = $sumColumn($last7, 'carbon_saved');
        $carbonPrev7 = $sumColumn($prev7, 'carbon_saved');
        $transactionsLast7 = (int) round($sumColumn($last7, 'transactions'));
        $pointsLast7 = $sumColumn($last7, 'points_awarded');

        $trendSummary = [
            'carbon_last7' => $carbonLast7,
            'carbon_prev7' => $carbonPrev7,
            'carbon_delta' => $carbonLast7 - $carbonPrev7,
            'carbon_delta_rate' => $this->safeDivide($carbonLast7 - $carbonPrev7, max($carbonPrev7, 1)),
            'transactions_last7' => $transactionsLast7,
            'points_last7' => $pointsLast7,
            'average_daily_carbon_30d' => $trendCount > 0 ? $trendTotals['carbon_saved'] / max($trendCount, 1) : 0.0,
        ];

        $pendingTxStmt = $this->db->prepare("SELECT id, uid AS user_id, username, points, status, created_at
                FROM points_transactions
                WHERE {$activityDeletedCondition} AND LOWER(status) = 'pending'
                ORDER BY created_at DESC
                LIMIT 5");
        $pendingTxStmt->execute();
        $pendingTransactionsList = $pendingTxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $pendingRecordsStmt = $this->db->prepare("SELECT cr.id, cr.user_id, u.username, cr.activity_id,
                    ca.name_zh AS activity_name_zh, ca.name_en AS activity_name_en,
                    cr.carbon_saved, cr.points_earned, cr.created_at
                FROM carbon_records cr
                LEFT JOIN users u ON u.id = cr.user_id
                LEFT JOIN carbon_activities ca ON ca.id = cr.activity_id
                WHERE {$carbonDeletedConditionAliasedCr} AND LOWER(cr.status) = 'pending'
                ORDER BY cr.created_at DESC
                LIMIT 5");
        $pendingRecordsStmt->execute();
        $pendingRecordsList = $pendingRecordsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $latestUsersStmt = $this->db->prepare("SELECT id, username, email, status, created_at
                FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
        $latestUsersStmt->execute();
        $latestUsers = $latestUsersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $users = $this->normalizeUsersStats($userStatsRaw);
        $transactions = $this->normalizeTransactionStats($transactionStatsRaw, $txWindowRaw, $carbonStatsRaw, $carbonWindowRaw);
        $exchanges = $this->normalizeExchangeStats($exchangeStatsRaw);
        $messagesSummary = $this->normalizeMessageStats($messageStatsRaw);
        $priorityStats = $this->normalizeMessagePriorityBreakdown($messagePriorityRows, $messagesSummary);
        $trendSeries = $this->normalizeMessageDailySeries($messageTrendRows, $messageTrendStart, $now, $messagesSummary);

        $messages = $messagesSummary;
        $messages['priority_breakdown'] = $priorityStats;
        $messages['daily_counts'] = $trendSeries;
        $activities = $this->normalizeActivityStats($activityRecordStatsRaw, $activityCatalogStatsRaw);
        $carbon = $this->normalizeCarbonStats($carbonStatsRaw, $carbonWindowRaw, $trendTotals, $trendCount);

        $recent = [
            'pending_transactions' => $this->formatPendingTransactions($pendingTransactionsList),
            'pending_carbon_records' => $this->formatPendingCarbonRecords($pendingRecordsList),
            'latest_users' => $this->formatLatestUsers($latestUsers),
        ];

        return [
            'users' => $users,
            'transactions' => $transactions,
            'exchanges' => $exchanges,
            'messages' => $messages,
            'activities' => $activities,
            'carbon' => $carbon,
            'trends' => $trendData,
            'trend_summary' => $trendSummary,
            'recent' => $recent,
            'generated_at' => $now->format(DATE_ATOM),
        ];
    }

    private function buildPublicSummary(array $adminData): array
    {
        $generatedAt = new DateTimeImmutable('now', $this->timezone);
        $users = $adminData['users'] ?? [];
        $carbon = $adminData['carbon'] ?? [];
        $activities = $adminData['activities'] ?? [];
        $transactions = $adminData['transactions'] ?? [];
        $messagesSummary = $adminData['messages'] ?? [];
        $trend = $adminData['trend_summary'] ?? [];

        return [
            'generated_at' => $generatedAt->format(DATE_ATOM),
            'total_users' => $this->toInt($users['total_users'] ?? 0),
            'active_users' => $this->toInt($users['active_users'] ?? 0),
            'new_users_30d' => $this->toInt($users['new_users_30d'] ?? 0),
            'total_records' => $this->toInt($activities['total_records'] ?? ($carbon['total_records'] ?? 0)),
            'approved_records' => $this->toInt($activities['approved_records'] ?? ($carbon['approved_records'] ?? 0)),
            'pending_records' => $this->toInt($activities['pending_records'] ?? ($carbon['pending_records'] ?? 0)),
            'total_carbon_saved' => round($this->toFloat($carbon['total_carbon_saved'] ?? 0.0), 2),
            'average_daily_carbon_30d' => round($this->toFloat($trend['average_daily_carbon_30d'] ?? 0.0), 2),
            'carbon_last7' => round($this->toFloat($trend['carbon_last7'] ?? 0.0), 2),
            'total_points_awarded' => round($this->toFloat($transactions['total_points_awarded'] ?? ($carbon['total_points_earned'] ?? 0.0)), 2),
            'transactions_last7' => $this->toInt($trend['transactions_last7'] ?? 0),
            'total_messages' => $this->toInt($messagesSummary['total_messages'] ?? 0),
            'unread_messages' => $this->toInt($messagesSummary['unread_messages'] ?? 0),
            'read_messages' => $this->toInt($messagesSummary['read_messages'] ?? 0),
            'unread_ratio' => round($this->toFloat($messagesSummary['unread_ratio'] ?? 0.0), 4),
            'messages' => [
                'total_messages' => $this->toInt($messagesSummary['total_messages'] ?? 0),
                'unread_messages' => $this->toInt($messagesSummary['unread_messages'] ?? 0),
                'read_messages' => $this->toInt($messagesSummary['read_messages'] ?? 0),
                'unread_ratio' => round($this->toFloat($messagesSummary['unread_ratio'] ?? 0.0), 4),
            ],
        ];
    }

    private function normalizeUsersStats(array $row): array
    {
        $total = $this->toInt($row['total_users'] ?? 0);
        $active = $this->toInt($row['active_users'] ?? 0);
        $inactive = $this->toInt($row['inactive_users'] ?? 0);
        if ($inactive === 0 && $total >= $active) {
            $inactive = max(0, $total - $active);
        }
        $newThirty = $this->toInt($row['new_users_30d'] ?? 0);

        return [
            'total_users' => $total,
            'active_users' => $active,
            'inactive_users' => $inactive,
            'new_users_30d' => $newThirty,
            'active_ratio' => $this->safeDivide((float) $active, max($total, 1)),
            'new_users_ratio' => $this->safeDivide((float) $newThirty, max($total, 1)),
        ];
    }

    private function normalizeTransactionStats(array $row, array $windowRow, array $carbonRow, array $carbonWindowRow): array
    {
        $total = $this->toInt($row['total_transactions'] ?? 0);
        $pending = $this->toInt($row['pending_transactions'] ?? 0);
        $approved = $this->toInt($row['approved_transactions'] ?? 0);
        $rejected = $this->toInt($row['rejected_transactions'] ?? 0);
        $points = $this->toFloat($row['total_points_awarded'] ?? 0);
        if ($points <= 0.0) {
            $points = $this->toFloat($carbonRow['total_points_earned'] ?? 0);
        }
        $windowTransactions = $this->toInt($windowRow['total_transactions'] ?? 0);
        $windowPoints = $this->toFloat($windowRow['total_points_awarded'] ?? 0);
        if ($windowPoints <= 0.0) {
            $windowPoints = $this->toFloat($carbonWindowRow['points_earned'] ?? 0);
        }
        $totalCarbon = $this->toFloat($carbonRow['total_carbon_saved'] ?? 0);

        $approvedForAverage = $approved > 0 ? $approved : $this->toInt($carbonRow['approved_records'] ?? 0);
        $avgPoints = $approvedForAverage > 0 ? round($points / $approvedForAverage, 2) : 0.0;

        return [
            'total_transactions' => $total,
            'pending_transactions' => $pending,
            'approved_transactions' => $approved,
            'rejected_transactions' => $rejected,
            'total_points_awarded' => $points,
            'approval_rate' => $this->safeDivide((float) $approved, max($total, 1)),
            'pending_ratio' => $this->safeDivide((float) $pending, max($total, 1)),
            'avg_points_per_transaction' => $avgPoints,
            'last7_transactions' => $windowTransactions,
            'last7_points_awarded' => $windowPoints,
            'total_carbon_saved' => $totalCarbon,
        ];
    }

    private function normalizeExchangeStats(array $row): array
    {
        $total = $this->toInt($row['total_exchanges'] ?? 0);
        $pending = $this->toInt($row['pending_exchanges'] ?? 0);
        $completed = $this->toInt($row['completed_exchanges'] ?? 0);
        $other = $this->toInt($row['other_exchanges'] ?? 0);
        if ($other === 0 && $total >= ($pending + $completed)) {
            $other = max(0, $total - $pending - $completed);
        }
        $pointsSpent = $this->toFloat($row['total_points_spent'] ?? 0);

        return [
            'total_exchanges' => $total,
            'pending_exchanges' => $pending,
            'completed_exchanges' => $completed,
            'other_exchanges' => $other,
            'total_points_spent' => $pointsSpent,
            'completion_rate' => $this->safeDivide((float) $completed, max($total, 1)),
        ];
    }

    private function normalizeMessageStats(array $row): array
    {
        $total = $this->toInt($row['total_messages'] ?? 0);
        $unread = $this->toInt($row['unread_messages'] ?? 0);
        $read = $this->toInt($row['read_messages'] ?? 0);
        if ($read === 0 && $total >= $unread) {
            $read = max(0, $total - $unread);
        }

        return [
            'total_messages' => $total,
            'unread_messages' => $unread,
            'read_messages' => $read,
            'unread_ratio' => $this->safeDivide((float) $unread, max($total, 1)),
        ];
    }

    private function normalizeMessagePriorityBreakdown(array $rows, array $summary): array
    {
        if (empty($rows) && (($summary['total_messages'] ?? 0) <= 0)) {
            return [];
        }

        $orderMap = [
            'urgent' => 0,
            'high' => 1,
            'normal' => 2,
            'low' => 3,
        ];
        $result = [];

        foreach ($rows as $row) {
            $priorityRaw = (string) ($row['priority'] ?? 'normal');
            $priority = strtolower(trim($priorityRaw));
            if ($priority === '') {
                $priority = 'normal';
            }
            $total = $this->toInt($row['total'] ?? 0);
            $unread = $this->toInt($row['unread'] ?? 0);
            $read = $this->toInt($row['read'] ?? 0);
            if ($read === 0 && $total >= $unread) {
                $read = max(0, $total - $unread);
            }

            $result[] = [
                'priority' => $priority,
                'total' => $total,
                'unread' => $unread,
                'read' => $read,
                'unread_ratio' => $this->safeDivide((float) $unread, max($total, 1)),
                '_order' => $orderMap[$priority] ?? 99,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            if ($a['_order'] === $b['_order']) {
                return strcmp((string) $a['priority'], (string) $b['priority']);
            }
            return $a['_order'] <=> $b['_order'];
        });

        $normalized = array_map(static function (array $entry): array {
            unset($entry['_order']);
            return $entry;
        }, $result);

        if (empty($normalized) && ($summary['total_messages'] ?? 0) > 0) {
            $total = $this->toInt($summary['total_messages']);
            $unread = $this->toInt($summary['unread_messages'] ?? 0);
            $read = $this->toInt($summary['read_messages'] ?? max(0, $total - $unread));

            $normalized[] = [
                'priority' => 'normal',
                'total' => $total,
                'unread' => $unread,
                'read' => max(0, $total - $unread),
                'unread_ratio' => $this->safeDivide((float) $unread, max($total, 1)),
            ];
        }

        return $normalized;
    }

    private function normalizeMessageDailySeries(
        array $rows,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $summary
    ): array
    {
        $map = [];
        foreach ($rows as $row) {
            $labelRaw = (string) ($row['day_label'] ?? '');
            if ($labelRaw === '') {
                continue;
            }
            $label = substr($labelRaw, 0, 10);
            $total = $this->toInt($row['total'] ?? 0);
            $unread = $this->toInt($row['unread'] ?? 0);
            $read = $this->toInt($row['read'] ?? 0);
            if ($read === 0 && $total >= $unread) {
                $read = max(0, $total - $unread);
            }
            $map[$label] = [
                'total' => $total,
                'unread' => $unread,
                'read' => $read,
            ];
        }

        $series = [];
        $current = $start->setTime(0, 0, 0);
        $endDate = $end->setTime(0, 0, 0);
        $hasData = false;

        while ($current <= $endDate) {
            $label = $current->format('Y-m-d');
            $stats = $map[$label] ?? ['total' => 0, 'unread' => 0, 'read' => 0];
            $series[] = [
                'date' => $label,
                'total' => $stats['total'],
                'unread' => $stats['unread'],
                'read' => $stats['read'],
            ];
            if ($stats['total'] > 0 || $stats['unread'] > 0) {
                $hasData = true;
            }
            $current = $current->modify('+1 day');
        }

        if (!$hasData && ($summary['total_messages'] ?? 0) > 0) {
            $total = $this->toInt($summary['total_messages']);
            $unread = $this->toInt($summary['unread_messages'] ?? 0);
            $read = $this->toInt($summary['read_messages'] ?? max(0, $total - $unread));

            return [[
                'date' => $endDate->format('Y-m-d'),
                'total' => $total,
                'unread' => $unread,
                'read' => $read,
            ]];
        }

        return $series;
    }

    private function normalizeActivityStats(array $recordRow, array $catalogRow): array
    {
        $totalRecords = $this->toInt($recordRow['total_records'] ?? 0);
        $approvedRecords = $this->toInt($recordRow['approved_records'] ?? 0);
        $pendingRecords = $this->toInt($recordRow['pending_records'] ?? 0);
        $rejectedRecords = $this->toInt($recordRow['rejected_records'] ?? 0);

        $totalCatalog = $this->toInt($catalogRow['total_activities'] ?? 0);
        $activeCatalog = $this->toInt($catalogRow['active_activities'] ?? 0);
        $inactiveCatalog = $this->toInt($catalogRow['inactive_activities'] ?? max(0, $totalCatalog - $activeCatalog));

        return [
            'total_records' => $totalRecords,
            'approved_records' => $approvedRecords,
            'pending_records' => $pendingRecords,
            'rejected_records' => $rejectedRecords,
            'approved_activities' => $approvedRecords,
            'pending_activities' => $pendingRecords,
            'rejected_activities' => $rejectedRecords,
            'total_activities' => $totalCatalog,
            'active_activities' => $activeCatalog,
            'inactive_activities' => $inactiveCatalog,
        ];
    }

    private function normalizeCarbonStats(array $row, array $windowRow, array $trendTotals, int $trendCount): array
    {
        $totalRecords = $this->toInt($row['total_records'] ?? 0);
        $pendingRecords = $this->toInt($row['pending_records'] ?? 0);
        $approvedRecords = $this->toInt($row['approved_records'] ?? 0);
        $rejectedRecords = $this->toInt($row['rejected_records'] ?? 0);
        $totalCarbon = $this->toFloat($row['total_carbon_saved'] ?? 0);
        $totalPointsEarned = $this->toFloat($row['total_points_earned'] ?? 0);
        $windowCarbon = $this->toFloat($windowRow['carbon_saved'] ?? 0);
        $windowPoints = $this->toFloat($windowRow['points_earned'] ?? 0);
        $windowPending = $this->toInt($windowRow['pending_records'] ?? 0);
        $windowApproved = $this->toInt($windowRow['approved_records'] ?? 0);
        $averageDaily = $trendCount > 0
            ? $trendTotals['carbon_saved'] / max($trendCount, 1)
            : ($approvedRecords > 0 ? $totalCarbon / $approvedRecords : 0.0);

        return [
            'total_records' => $totalRecords,
            'pending_records' => $pendingRecords,
            'approved_records' => $approvedRecords,
            'rejected_records' => $rejectedRecords,
            'total_carbon_saved' => $totalCarbon,
            'total_points_earned' => $totalPointsEarned,
            'last7_carbon_saved' => $windowCarbon,
            'last7_points_earned' => $windowPoints,
            'last7_pending_records' => $windowPending,
            'last7_approved_records' => $windowApproved,
            'approval_rate' => $this->safeDivide((float) $approvedRecords, max($totalRecords, 1)),
            'average_carbon_per_record' => $approvedRecords > 0 ? round($totalCarbon / $approvedRecords, 4) : 0.0,
            'average_daily_carbon' => round($averageDaily, 4),
        ];
    }

    private function formatPendingTransactions(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => $this->toInt($row['id'] ?? 0),
                'user_id' => $this->toInt($row['user_id'] ?? $row['uid'] ?? 0),
                'username' => $row['username'] ?? null,
                'points' => $this->toFloat($row['points'] ?? 0),
                'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function formatPendingCarbonRecords(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => $this->toInt($row['id'] ?? 0),
                'user_id' => $this->toInt($row['user_id'] ?? 0),
                'username' => $row['username'] ?? null,
                'activity_id' => $this->toInt($row['activity_id'] ?? 0),
                'activity_name_zh' => $row['activity_name_zh'] ?? null,
                'activity_name_en' => $row['activity_name_en'] ?? null,
                'carbon_saved' => $this->toFloat($row['carbon_saved'] ?? 0),
                'points_earned' => $this->toFloat($row['points_earned'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function formatLatestUsers(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => $this->toInt($row['id'] ?? 0),
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $filtered = preg_replace('/[^0-9\\-]/', '', $value);
            return (int) ($filtered ?? 0);
        }
        return (int) $value;
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $filtered = preg_replace('/[^0-9\\-\\.]/', '', $value);
            return (float) ($filtered ?? 0);
        }
        return (float) $value;
    }

    private function safeDivide(float $numerator, float $denominator, int $scale = 4): float
    {
        if ($denominator <= 0.0) {
            return 0.0;
        }
        return round($numerator / $denominator, $scale);
    }

    private function validateTtl(?int $provided, int $default): int
    {
        $ttl = $provided ?? $default;
        if ($ttl <= 0) {
            return $default > 0 ? $default : 300;
        }
        return $ttl;
    }

    private function readCache(string $key, int $ttl): ?array
    {
        $file = $this->getCacheFilePath($key);
        if (!is_file($file)) {
            return null;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['generated_at'], $data['data'])) {
            return null;
        }
        try {
            $generated = new DateTimeImmutable((string) $data['generated_at']);
        } catch (\Throwable $e) {
            return null;
        }
        $expires = $generated->add(new DateInterval('PT' . max($ttl, 1) . 'S'));
        if ($expires <= new DateTimeImmutable('now', $this->timezone)) {
            return null;
        }
        return [
            'generated_at' => $data['generated_at'],
            'data' => $data['data'],
        ];
    }

    private function writeCache(string $key, array $data, int $ttl): void
    {
        $file = $this->getCacheFilePath($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $generated = new DateTimeImmutable('now', $this->timezone);
        $payload = [
            'generated_at' => $generated->format(DATE_ATOM),
            'expires_at' => $generated->add(new DateInterval('PT' . max($ttl, 1) . 'S'))->format(DATE_ATOM),
            'data' => $data,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            return;
        }

        @file_put_contents($file, $encoded, LOCK_EX);
    }

    private function getCacheFilePath(string $key): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\\-]/', '_', $key);
        return $this->cacheDir . DIRECTORY_SEPARATOR . $sanitized . '_stats.json';
    }
}
