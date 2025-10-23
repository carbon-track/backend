<?php

declare(strict_types=1);

namespace CarbonTrack\Jobs;

use CarbonTrack\Services\EmailService;
use Monolog\Logger;
use CarbonTrack\Services\NotificationPreferenceService;
use CarbonTrack\Models\Message;

class EmailJobRunner
{
    /**
     * Execute an email job immediately.
     *
     * @param array<string,mixed> $payload
     */
    public static function run(EmailService $emailService, Logger $logger, string $jobType, array $payload): void
    {
        if ($jobType === '') {
            $logger->warning('Email job received without a job type.');
            return;
        }

        try {
            switch ($jobType) {
                case 'message_notification':
                    self::runMessageNotificationJob($emailService, $logger, $payload);
                    break;

                case 'message_notification_bulk':
                    self::runBulkNotificationJob($emailService, $logger, $payload);
                    break;

                case 'exchange_confirmation':
                    self::runExchangeConfirmationJob($emailService, $logger, $payload);
                    break;

                case 'exchange_status_update':
                    self::runExchangeStatusUpdateJob($emailService, $logger, $payload);
                    break;

                case 'activity_approved_notification':
                    self::runActivityApprovedJob($emailService, $logger, $payload);
                    break;

                case 'activity_rejected_notification':
                    self::runActivityRejectedJob($emailService, $logger, $payload);
                    break;

                case 'broadcast_announcement':
                    self::runBroadcastAnnouncementJob($emailService, $logger, $payload);
                    break;

                case 'carbon_record_review_summary':
                    self::runCarbonRecordReviewSummaryJob($emailService, $logger, $payload);
                    break;

                default:
                    $logger->warning('Unknown email job type received', ['job_type' => $jobType]);
                    break;
            }
        } catch (\Throwable $e) {
            $logger->error('Unhandled exception while executing email job', [
                'job_type' => $jobType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runMessageNotificationJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $email = (string) ($payload['email'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $category = (string) ($payload['category'] ?? NotificationPreferenceService::CATEGORY_SYSTEM);
        $priority = (string) ($payload['priority'] ?? Message::PRIORITY_NORMAL);
        $receiverId = isset($payload['receiver_id']) ? (int) $payload['receiver_id'] : 0;
        $notificationType = (string) ($payload['type'] ?? '');

        $sent = $emailService->sendMessageNotification(
            $email,
            $name !== '' ? $name : $email,
            $subject,
            $content,
            $category,
            $priority
        );

        if (!$sent) {
            $logger->debug('Message email was skipped due to user preferences or simulation mode', [
                'receiver_id' => $receiverId,
                'category' => $category,
                'notification_type' => $notificationType,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runBulkNotificationJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $recipients = $payload['recipients'] ?? [];
        if (!is_array($recipients)) {
            $logger->warning('Bulk email job received invalid recipients payload.');
            return;
        }

        $subject = (string) ($payload['subject'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $category = (string) ($payload['category'] ?? NotificationPreferenceService::CATEGORY_SYSTEM);
        $priority = (string) ($payload['priority'] ?? Message::PRIORITY_NORMAL);
        $notificationType = (string) ($payload['type'] ?? '');

        $sent = $emailService->sendMessageNotificationToMany(
            $recipients,
            $subject,
            $content,
            $category,
            $priority
        );

        if (!$sent) {
            $logger->debug('Bulk message email was skipped', [
                'subject' => $subject,
                'category' => $category,
                'priority' => $priority,
                'notification_type' => $notificationType,
                'recipient_count' => count($recipients),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runActivityApprovedJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            $logger->warning('Activity approved job missing recipient email.');
            return;
        }

        $name = (string) ($payload['name'] ?? $email);
        $activity = (string) ($payload['activity_name'] ?? '');
        $points = (float) ($payload['points'] ?? 0);
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        try {
            $emailService->sendActivityApprovedNotification(
                $email,
                $name !== '' ? $name : $email,
                $activity,
                $points
            );
        } catch (\Throwable $e) {
            $logger->warning('Failed to send activity approved email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runActivityRejectedJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            $logger->warning('Activity rejected job missing recipient email.');
            return;
        }

        $name = (string) ($payload['name'] ?? $email);
        $activity = (string) ($payload['activity_name'] ?? '');
        $reason = (string) ($payload['reason'] ?? '');
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        try {
            $emailService->sendActivityRejectedNotification(
                $email,
                $name !== '' ? $name : $email,
                $activity,
                $reason
            );
        } catch (\Throwable $e) {
            $logger->warning('Failed to send activity rejected email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runBroadcastAnnouncementJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $recipients = $payload['recipients'] ?? [];
        if (!is_array($recipients) || empty($recipients)) {
            $logger->debug('Broadcast announcement job skipped due to empty recipients.');
            return;
        }

        $title = (string) ($payload['title'] ?? '');
        $content = (string) ($payload['content'] ?? '');
        $priority = (string) ($payload['priority'] ?? Message::PRIORITY_NORMAL);

        $cleanedRecipients = [];
        foreach ($recipients as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $email = isset($entry['email']) ? trim((string) $entry['email']) : '';
            if ($email === '') {
                continue;
            }
            $name = isset($entry['name']) && $entry['name'] !== ''
                ? (string) $entry['name']
                : $email;
            $cleanedRecipients[] = [
                'email' => $email,
                'name' => $name,
            ];
        }

        if (empty($cleanedRecipients)) {
            $logger->debug('Broadcast announcement job skipped after cleaning recipients.');
            return;
        }

        try {
            $sent = $emailService->sendAnnouncementBroadcast(
                $cleanedRecipients,
                $title,
                $content,
                $priority
            );

            if (!$sent) {
                $logger->debug('Broadcast announcement email was not dispatched.', [
                    'recipient_count' => count($cleanedRecipients),
                    'priority' => $priority,
                ]);
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to send broadcast announcement email', [
                'error' => $e->getMessage(),
                'recipient_count' => count($cleanedRecipients),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runCarbonRecordReviewSummaryJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            $logger->warning('Carbon record review summary job missing recipient email.');
            return;
        }

        $name = (string) ($payload['name'] ?? $email);
        $action = strtolower((string) ($payload['action'] ?? 'approve')) === 'approve' ? 'approve' : 'reject';
        $title = (string) ($payload['title'] ?? ($action === 'approve' ? 'Carbon record review approved' : 'Carbon record review result'));
        $records = $payload['records'] ?? [];
        if (!is_array($records)) {
            $records = [];
        }
        $reviewNote = isset($payload['review_note']) ? (string) $payload['review_note'] : null;
        $reviewedBy = isset($payload['reviewed_by']) ? (string) $payload['reviewed_by'] : null;

        try {
            $emailService->sendCarbonRecordReviewSummaryEmail(
                $email,
                $name,
                $action,
                $records,
                $title,
                $reviewNote,
                $reviewedBy
            );
        } catch (\Throwable $e) {
            $logger->warning('Failed to send carbon record review summary email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function runExchangeConfirmationJob(EmailService $emailService, Logger $logger, array $payload): void

    {
        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            $logger->warning('Exchange confirmation job missing recipient email.');
            return;
        }

        $name = (string) ($payload['name'] ?? $email);
        $product = (string) ($payload['product_name'] ?? '');
        $quantity = (int) ($payload['quantity'] ?? 1);
        $points = (float) ($payload['points_spent'] ?? 0);
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        try {
            $emailService->sendExchangeConfirmation(
                $email,
                $name !== '' ? $name : $email,
                $product,
                $quantity,
                $points
            );
        } catch (\Throwable $e) {
            $logger->warning('Failed to send exchange confirmation email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function runExchangeStatusUpdateJob(EmailService $emailService, Logger $logger, array $payload): void
    {
        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            $logger->warning('Exchange status update job missing recipient email.');
            return;
        }

        $name = (string) ($payload['name'] ?? $email);
        $product = (string) ($payload['product_name'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $notes = (string) ($payload['notes'] ?? '');
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;

        try {
            $emailService->sendExchangeStatusUpdate(
                $email,
                $name !== '' ? $name : $email,
                $product,
                $status,
                $notes
            );
        } catch (\Throwable $e) {
            $logger->warning('Failed to send exchange status update email', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
