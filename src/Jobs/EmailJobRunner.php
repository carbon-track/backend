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

    /**

     * @param array<string,mixed> $payload

     */

    /**
     * @param array<string,mixed> $payload
     */
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
