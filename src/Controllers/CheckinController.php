<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Models\UserUsageStats;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CheckinService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\QuotaService;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CheckinController
{
    private DateTimeZone $timezone;

    public function __construct(
        private AuthService $authService,
        private CheckinService $checkinService,
        private QuotaService $quotaService,
        private AuditLogService $auditLogService,
        private Logger $logger,
        private ?ErrorLogService $errorLogService = null
    ) {
        $tzName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
        if (!$tzName) {
            $tzName = 'UTC';
        }
        $this->timezone = new DateTimeZone($tzName);
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            [$startDate, $endDate] = $this->resolveRange($request->getQueryParams());
            if (!$startDate || !$endDate) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Invalid date range',
                    'code' => 'INVALID_RANGE',
                ], 400);
            }

            $checkins = $this->checkinService->getCheckinsForRange((int) $user['id'], $startDate, $endDate);
            $stats = $this->checkinService->getUserStreakStats((int) $user['id']);
            $quota = $this->buildMakeupQuotaSummary($request, (int) $user['id']);
            $serverToday = (new DateTimeImmutable('now', $this->timezone))->format('Y-m-d');

            $this->auditLogService->logUserAction(
                (int) $user['id'],
                'checkin_calendar_viewed',
                ['range' => ['start' => $startDate, 'end' => $endDate]]
            );

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'range' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'checkins' => $checkins,
                    'stats' => $stats,
                    'makeup_quota' => $quota,
                    'meta' => [
                        'timezone' => $this->timezone->getName(),
                        'server_today' => $serverToday,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logException($e, $request, 'Failed to load checkin calendar');
            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to load checkin calendar',
            ], 500);
        }
    }

    public function makeup(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }
            $rawDate = isset($body['date']) ? trim((string) $body['date']) : '';
            $recordId = isset($body['record_id']) ? trim((string) $body['record_id']) : '';
            if ($rawDate === '') {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Missing date',
                    'code' => 'DATE_REQUIRED',
                ], 400);
            }
            if ($recordId === '') {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Missing record id',
                    'code' => 'RECORD_REQUIRED',
                ], 400);
            }

            $date = $this->normalizeDate($rawDate);
            if (!$date) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Invalid date',
                    'code' => 'INVALID_DATE',
                ], 400);
            }

            $today = new DateTimeImmutable('now', $this->timezone);
            if ($date > $today->setTime(0, 0, 0)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Cannot check in for future dates',
                    'code' => 'DATE_IN_FUTURE',
                ], 400);
            }

            $userModel = $this->authService->getCurrentUserModel($request);
            if (!$userModel) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $recordStmt = $this->checkinService->getConnection()->prepare(
                "SELECT id FROM carbon_records WHERE id = :rid AND user_id = :uid AND deleted_at IS NULL LIMIT 1"
            );
            $recordStmt->execute([
                'rid' => $recordId,
                'uid' => (int) $user['id'],
            ]);
            $recordExists = $recordStmt->fetchColumn();
            $recordStmt->closeCursor();
            if (!$recordExists) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Record not found',
                    'code' => 'RECORD_NOT_FOUND',
                ], 404);
            }

            $normalizedDate = $date->format('Y-m-d');
            if ($this->checkinService->hasCheckin((int) $user['id'], $normalizedDate)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Already checked in for this date',
                    'code' => 'ALREADY_CHECKED_IN',
                ], 409);
            }

            if (!$this->quotaService->checkAndConsume($userModel, 'checkin_makeup', 1)) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Makeup quota exceeded',
                    'code' => 'QUOTA_EXCEEDED',
                    'translation_key' => 'error.quota.exceeded',
                ], 429);
            }

            $note = isset($body['note']) ? trim((string) $body['note']) : null;
            $ok = $this->checkinService->createMakeupCheckin((int) $user['id'], $normalizedDate, $note, $recordId);
            if (!$ok) {
                return $this->json($response, [
                    'success' => false,
                    'message' => 'Failed to apply makeup checkin',
                    'code' => 'CHECKIN_FAILED',
                ], 500);
            }

            $this->auditLogService->logDataChange(
                'user',
                'checkin_makeup',
                (int) $user['id'],
                'user',
                'user_checkins',
                null,
                null,
                null,
                [
                    'checkin_date' => $normalizedDate,
                    'note' => $note,
                    'record_id' => $recordId,
                ]
            );

            $stats = $this->checkinService->getUserStreakStats((int) $user['id']);
            $quota = $this->buildMakeupQuotaSummary($request, (int) $user['id']);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'checkin_date' => $normalizedDate,
                    'stats' => $stats,
                    'makeup_quota' => $quota,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logException($e, $request, 'Failed to apply makeup checkin');
            return $this->json($response, [
                'success' => false,
                'message' => 'Failed to apply makeup checkin',
            ], 500);
        }
    }

    private function resolveRange(array $params): array
    {
        $month = isset($params['month']) ? trim((string) $params['month']) : '';
        $startRaw = isset($params['start_date']) ? trim((string) $params['start_date']) : '';
        $endRaw = isset($params['end_date']) ? trim((string) $params['end_date']) : '';

        $start = null;
        $end = null;

        if ($month !== '') {
            $monthDate = DateTimeImmutable::createFromFormat('Y-m', $month, $this->timezone);
            if ($monthDate) {
                $start = $monthDate->modify('first day of this month');
                $end = $monthDate->modify('last day of this month');
            }
        }

        if (!$start && $startRaw !== '') {
            $start = $this->normalizeDate($startRaw);
        }

        if (!$end && $endRaw !== '') {
            $end = $this->normalizeDate($endRaw);
        }

        if (!$start || !$end) {
            $now = new DateTimeImmutable('now', $this->timezone);
            $start = $start ?: $now->modify('first day of this month');
            $end = $end ?: $now->modify('last day of this month');
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        $maxDays = 370;
        $diffDays = (int) $start->diff($end)->format('%a');
        if ($diffDays > $maxDays) {
            $end = $start->modify(sprintf('+%d days', $maxDays));
        }

        return [
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        ];
    }

    private function buildMakeupQuotaSummary(Request $request, int $userId): array
    {
        $userModel = $this->authService->getCurrentUserModel($request);
        if (!$userModel) {
            return [
                'limit' => null,
                'used' => 0,
                'remaining' => null,
                'reset_at' => null,
            ];
        }

        $config = $this->quotaService->getEffectiveConfig($userModel, 'checkin_makeup');
        $limit = isset($config['monthly_limit']) ? (int) $config['monthly_limit'] : null;

        $usage = UserUsageStats::where('user_id', $userId)
            ->where('resource_key', 'checkin_makeup_monthly')
            ->first();

        $used = (int) ($usage?->counter ?? 0);
        $resetAt = $usage?->reset_at?->format('Y-m-d H:i:s');
        $remaining = $limit !== null ? max($limit - $used, 0) : null;

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
        ];
    }

    private function normalizeDate(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $candidate = DateTimeImmutable::createFromFormat('Y-m-d', $raw, $this->timezone);
        if ($candidate instanceof DateTimeImmutable && $candidate->format('Y-m-d') === $raw) {
            return $candidate->setTime(0, 0, 0);
        }

        try {
            $fallback = new DateTimeImmutable($raw, $this->timezone);
            return $fallback->setTime(0, 0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload === false ? '{}' : $payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function logException(\Throwable $e, Request $request, string $message): void
    {
        try {
            $this->logger->error($message, ['error' => $e->getMessage()]);
        } catch (\Throwable $ignore) {
            // ignore logger errors
        }

        if ($this->errorLogService) {
            try {
                $this->errorLogService->logException($e, $request, ['context_message' => $message]);
            } catch (\Throwable $ignore) {
                // ignore error log failures
            }
        }
    }
}
