<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Jobs\EmailJobRunner;
use CarbonTrack\Models\Message;
use CarbonTrack\Models\User;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\NotificationPreferenceService;
use Monolog\Logger;

class MessageService
{
    private Logger $logger;
    private AuditLogService $auditLogService;
    private ?EmailService $emailService;
    /** @var callable|null */
    private $userResolver;
    private bool $responseFlushedForAsyncEmail = false;
    private ?string $emailDispatcherScript = null;
    /** @var array<int,array{job_type:string,payload:array<string,mixed>}> */
    private array $pendingEmailJobs = [];
    private bool $emailShutdownRegistered = false;

    /**
     * @var array<string,string>
     */
    private const TYPE_CATEGORY_MAP = [
        Message::TYPE_SYSTEM => NotificationPreferenceService::CATEGORY_SYSTEM,
        Message::TYPE_NOTIFICATION => NotificationPreferenceService::CATEGORY_SYSTEM,
        Message::TYPE_APPROVAL => NotificationPreferenceService::CATEGORY_ACTIVITY,
        Message::TYPE_REJECTION => NotificationPreferenceService::CATEGORY_ACTIVITY,
        Message::TYPE_EXCHANGE => NotificationPreferenceService::CATEGORY_TRANSACTION,
        Message::TYPE_WELCOME => NotificationPreferenceService::CATEGORY_SYSTEM,
        Message::TYPE_REMINDER => NotificationPreferenceService::CATEGORY_SYSTEM,
        'record_submitted' => NotificationPreferenceService::CATEGORY_ACTIVITY,
        'record_approved' => NotificationPreferenceService::CATEGORY_ACTIVITY,
        'record_rejected' => NotificationPreferenceService::CATEGORY_ACTIVITY,
        'new_record_pending' => NotificationPreferenceService::CATEGORY_ACTIVITY,
        'product_exchanged' => NotificationPreferenceService::CATEGORY_TRANSACTION,
        'new_exchange_pending' => NotificationPreferenceService::CATEGORY_TRANSACTION,
        'exchange_status_updated' => NotificationPreferenceService::CATEGORY_TRANSACTION,
        'direct_message' => NotificationPreferenceService::CATEGORY_MESSAGE,
        'message' => NotificationPreferenceService::CATEGORY_MESSAGE,
    ];

    /**
     * Message types that already have a dedicated email notification.
     *
     * @var array<int,string>
     */
    private const TYPES_WITH_DEDICATED_EMAIL = [
        'product_exchanged',
        'exchange_status_updated',
    ];

    public function __construct(Logger $logger, AuditLogService $auditLogService, ?EmailService $emailService = null)
    {
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
        $this->emailService = $emailService;
        $this->userResolver = null;
        $scriptPath = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'email_dispatcher.php');
        $this->emailDispatcherScript = $scriptPath !== false ? $scriptPath : null;
    }

    /**
     * @param callable|null $resolver receives (int $userId): ?User
     */
    public function setUserResolver(?callable $resolver): void
    {
        $this->userResolver = $resolver;
    }

    /**
     * Send a message between users
     */
    public function sendMessage(
        int $receiverId,
        string $type,
        string $title,
        string $content,
        string $priority = Message::PRIORITY_NORMAL,
        ?int $senderId = null,
        bool $sendEmail = true
    ): Message {
        $message = Message::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'title' => $title,
            'content' => $content,
            'is_read' => false,
            'priority' => $priority
        ]);

        $this->logger->info('Message sent', [
            'message_id' => $message->id,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'type' => $type,
            'priority' => $priority
        ]);

        // Log the message sending
        $this->auditLogService->log([
            'user_id' => $senderId,
            'action' => 'message_sent',
            'entity_type' => 'message',
            'entity_id' => $message->id,
            'new_value' => json_encode([
                'receiver_id' => $receiverId,
                'title' => $title,
                'type' => $type,
                'priority' => $priority
            ]),
            'notes' => 'Message sent to user ' . $receiverId
        ]);

        if ($sendEmail) {
            $this->maybeSendLinkedEmail($receiverId, $title, $content, $type, $priority);
        }

        return $message;
    }

    /**
     * Send system message
     */
    public function sendSystemMessage(
        int $receiverId,
        string $title,
        string $content,
        string $type = Message::TYPE_SYSTEM,
        string $priority = Message::PRIORITY_NORMAL,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null,
        bool $sendEmail = true
    ): Message {
        $message = Message::createSystemMessage(
            $receiverId,
            $title,
            $content,
            $type,
            $priority,
            $relatedEntityType,
            $relatedEntityId
        );

        if ($sendEmail) {
            $this->maybeSendLinkedEmail($receiverId, $title, $content, $type, $priority);
        }

        return $message;
    }

    /**
     * Send carbon tracking submission notification
     */
    /**
     * @param mixed $transaction Backward-compatible transaction object/array
     */
    public function sendCarbonTrackingSubmissionNotification($transaction): Message
    {
        $user = is_array($transaction) ? ($transaction['user'] ?? null) : ($transaction->user ?? null);
        $activity = is_array($transaction) ? ($transaction['activity'] ?? null) : ($transaction->activity ?? null);
        
        $title = '碳减排记录提交成功 / Carbon Tracking Record Submitted';
        $content = "您的碳减排记录已成功提交，正在等待审核。\n\n" .
                  "活动：{$activity->getCombinedName()}\n" .
                  "数据：{$transaction->raw} {$activity->unit}\n" .
                  "预计积分：{$transaction->points}\n" .
                  "提交时间：{$transaction->created_at}\n\n" .
                  "我们将在1-3个工作日内完成审核，请耐心等待。\n\n" .
                  "Your carbon reduction record has been submitted successfully and is pending review.\n\n" .
                  "Activity: {$activity->getCombinedName()}\n" .
                  "Data: {$transaction->raw} {$activity->unit}\n" .
                  "Expected Points: {$transaction->points}\n" .
                  "Submitted: {$transaction->created_at}\n\n" .
                  "We will complete the review within 1-3 business days. Please be patient.";

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_NOTIFICATION,
            Message::PRIORITY_NORMAL,
            null,
            null
        );
    }

    /**
     * Send carbon tracking approval notification
     */
    /**
     * @param mixed $transaction Backward-compatible transaction object/array
     */
    public function sendCarbonTrackingApprovalNotification($transaction, User $approver): Message
    {
        $user = is_array($transaction) ? ($transaction['user'] ?? null) : ($transaction->user ?? null);
        $activity = is_array($transaction) ? ($transaction['activity'] ?? null) : ($transaction->activity ?? null);
        
        $title = '🎉 碳减排记录审核通过 / Carbon Tracking Record Approved';
        $content = "恭喜！您的碳减排记录已通过审核。\n\n" .
                  "活动：{$activity->getCombinedName()}\n" .
                  "数据：{$transaction->raw} {$activity->unit}\n" .
                  "获得积分：{$transaction->points}\n" .
                  "审核时间：{$transaction->approved_at}\n" .
                  "审核员：{$approver->username}\n\n" .
                  "积分已添加到您的账户，当前总积分：{$user->points}\n\n" .
                  "感谢您为环保事业做出的贡献！\n\n" .
                  "Congratulations! Your carbon reduction record has been approved.\n\n" .
                  "Activity: {$activity->getCombinedName()}\n" .
                  "Data: {$transaction->raw} {$activity->unit}\n" .
                  "Points Earned: {$transaction->points}\n" .
                  "Approved: {$transaction->approved_at}\n" .
                  "Reviewer: {$approver->username}\n\n" .
                  "Points have been added to your account. Current total: {$user->points}\n\n" .
                  "Thank you for your contribution to environmental protection!";

        $message = $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_APPROVAL,
            Message::PRIORITY_HIGH,
            null,
            null,
            false
        );

        $this->sendActivityApprovedEmail($user, $activity, $transaction);

        return $message;
    }

    /**
     * Send carbon tracking rejection notification
     */
    /**
     * @param mixed $transaction Backward-compatible transaction object/array
     */
    public function sendCarbonTrackingRejectionNotification($transaction, User $approver, ?string $reason = null): Message
    {
        $user = is_array($transaction) ? ($transaction['user'] ?? null) : ($transaction->user ?? null);
        $activity = is_array($transaction) ? ($transaction['activity'] ?? null) : ($transaction->activity ?? null);
        
        $title = '❌ 碳减排记录审核未通过 / Carbon Tracking Record Rejected';
        $content = "很抱歉，您的碳减排记录未通过审核。\n\n" .
                  "活动：{$activity->getCombinedName()}\n" .
                  "数据：{$transaction->raw} {$activity->unit}\n" .
                  "审核时间：{$transaction->approved_at}\n" .
                  "审核员：{$approver->username}\n\n";
        
        if ($reason) {
            $content .= "拒绝原因：{$reason}\n\n";
        }
        
        $content .= "请检查提交的信息是否准确完整，您可以重新提交正确的记录。\n\n" .
                   "如有疑问，请联系管理员。\n\n" .
                   "Sorry, your carbon reduction record was not approved.\n\n" .
                   "Activity: {$activity->getCombinedName()}\n" .
                   "Data: {$transaction->raw} {$activity->unit}\n" .
                   "Reviewed: {$transaction->approved_at}\n" .
                   "Reviewer: {$approver->username}\n\n";
        
        if ($reason) {
            $content .= "Reason: {$reason}\n\n";
        }
        
        $content .= "Please check if the submitted information is accurate and complete. You can resubmit the correct record.\n\n" .
                   "If you have any questions, please contact the administrator.";

        $message = $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_REJECTION,
            Message::PRIORITY_HIGH,
            null,
            null,
            false
        );

        $this->sendActivityRejectedEmail($user, $activity, $reason);

        return $message;
    }

    /**
     * Send product exchange confirmation notification
     */
    /**
     * @param mixed $exchange Backward-compatible exchange object/array
     */
    public function sendProductExchangeConfirmation($exchange): Message
    {
        $user = is_array($exchange) ? ($exchange['user'] ?? null) : ($exchange->user ?? null);
        $product = is_array($exchange) ? ($exchange['product'] ?? null) : ($exchange->product ?? null);
        
        $title = '🛍️ 商品兑换成功 / Product Exchange Successful';
        $content = "您已成功兑换商品！\n\n" .
                  "商品：{$product->name}\n" .
                  "消耗积分：{$exchange->points_spent}\n" .
                  "兑换时间：{$exchange->created_at}\n" .
                  "剩余积分：{$user->points}\n\n" .
                  "我们将尽快为您安排商品配送，请保持联系方式畅通。\n\n" .
                  "感谢您对环保事业的支持！\n\n" .
                  "You have successfully exchanged for a product!\n\n" .
                  "Product: {$product->name}\n" .
                  "Points Spent: {$exchange->points_spent}\n" .
                  "Exchange Time: {$exchange->created_at}\n" .
                  "Remaining Points: {$user->points}\n\n" .
                  "We will arrange product delivery as soon as possible. Please keep your contact information available.\n\n" .
                  "Thank you for supporting environmental protection!";

        $message = $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_EXCHANGE,
            Message::PRIORITY_NORMAL,
            null,
            null,
            false
        );

        $quantity = is_array($exchange) ? ($exchange['quantity'] ?? 1) : ($exchange->quantity ?? 1);
        $pointsSpent = is_array($exchange) ? ($exchange['points_spent'] ?? 0) : ($exchange->points_spent ?? 0);
        $this->sendExchangeConfirmationEmail($user, $product, (int) $quantity, (float) $pointsSpent);

        return $message;
    }

    /**
     * Send welcome message to new user
     */
    public function sendWelcomeMessage(User $user): Message
    {
        return Message::createWelcomeMessage($user->id);
    }

    /**
     * Send reminder for pending transactions
     */
    public function sendPendingTransactionReminder(User $user, int $pendingCount): Message
    {
        $title = '📋 待审核记录提醒 / Pending Records Reminder';
        $content = "您有 {$pendingCount} 条碳减排记录正在等待审核。\n\n" .
                  "我们正在努力处理您的提交，通常在1-3个工作日内完成审核。\n\n" .
                  "如果您的记录超过5个工作日仍未审核，请联系管理员。\n\n" .
                  "感谢您的耐心等待！\n\n" .
                  "You have {$pendingCount} carbon reduction records pending review.\n\n" .
                  "We are working hard to process your submissions, usually within 1-3 business days.\n\n" .
                  "If your records have not been reviewed for more than 5 business days, please contact the administrator.\n\n" .
                  "Thank you for your patience!";

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_REMINDER,
            Message::PRIORITY_LOW
        );
    }

    /**
     * Send low points warning
     */
    public function sendLowPointsWarning(User $user): Message
    {
        $title = '⚠️ 积分余额不足 / Low Points Balance';
        $content = "您的积分余额较低（当前：{$user->points}），可能无法兑换心仪的商品。\n\n" .
                  "建议您：\n" .
                  "• 记录更多的碳减排活动\n" .
                  "• 参与平台的环保挑战\n" .
                  "• 邀请朋友加入CarbonTrack\n\n" .
                  "让我们一起为地球环保贡献更多力量！\n\n" .
                  "Your points balance is low (current: {$user->points}), you may not be able to exchange for desired products.\n\n" .
                  "We suggest you:\n" .
                  "• Record more carbon reduction activities\n" .
                  "• Participate in platform environmental challenges\n" .
                  "• Invite friends to join CarbonTrack\n\n" .
                  "Let's contribute more to environmental protection together!";

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_REMINDER,
            Message::PRIORITY_LOW
        );
    }

    /**
     * Send admin notification for new pending transaction
     */
    /**
     * @param mixed $transaction Backward-compatible transaction object/array
     */
    public function sendAdminPendingTransactionNotification($transaction): void
    {
        $user = is_array($transaction) ? ($transaction['user'] ?? null) : ($transaction->user ?? null);
        $activity = is_array($transaction) ? ($transaction['activity'] ?? null) : ($transaction->activity ?? null);
        
        $title = '🔍 新的碳减排记录待审核 / New Carbon Record Pending Review';
        $content = "有新的碳减排记录需要审核：\n\n" .
                  "用户：{$user->username} ({$user->email})\n" .
                  "活动：{$activity->getCombinedName()}\n" .
                  "数据：{$transaction->raw} {$activity->unit}\n" .
                  "预计积分：{$transaction->points}\n" .
                  "提交时间：{$transaction->created_at}\n\n" .
                  "请及时处理审核。\n\n" .
                  "New carbon reduction record pending review:\n\n" .
                  "User: {$user->username} ({$user->email})\n" .
                  "Activity: {$activity->getCombinedName()}\n" .
                  "Data: {$transaction->raw} {$activity->unit}\n" .
                  "Expected Points: {$transaction->points}\n" .
                  "Submitted: {$transaction->created_at}\n\n" .
                  "Please process the review promptly.";

        // Send to all admin users
        $adminUsers = User::where('is_admin', true)->where('status', 'active')->get();

        if ($adminUsers->isEmpty()) {
            return;
        }

        $batch = $adminUsers->map(function (User $admin): array {
            return [
                'id' => (int) $admin->id,
                'email' => $admin->email ? (string) $admin->email : null,
                'username' => $admin->username ? (string) $admin->username : null,
            ];
        })->all();

        $this->sendAdminNotificationBatch(
            $batch,
            'new_record_pending',
            $title,
            $content,
            Message::PRIORITY_HIGH
        );
    }

    /**
     * Get messages for a user with pagination
     */
    public function getMessagesForUser(
        int $userId,
        int $page = 1,
        int $limit = 20,
        ?string $type = null,
        ?bool $unreadOnly = null
    ): array {
        $query = Message::forUser($userId)->with(['sender']);
        
        if ($type) {
            $query->byType($type);
        }
        
        if ($unreadOnly !== null) {
            if ($unreadOnly) {
                $query->unread();
            } else {
                $query->read();
            }
        }
        
        $total = $query->count();
        $messages = $query->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return [
            'messages' => $messages->map(function ($message) {
                return [
                    'id' => $message->id,
                    'title' => $message->title,
                    'content' => $message->content,
                    // Columns may not exist in provided schema; return nulls for compatibility
                    'type' => null,
                    'priority' => null,
                    'is_read' => $message->is_read,
                    'read_at' => null,
                    'created_at' => $message->created_at,
                    'age' => $message->age,
                    'sender' => $message->sender ? [
                        'id' => $message->sender->id,
                        'username' => $message->sender->username
                    ] : null,
                    'related_entity_type' => null,
                    'related_entity_id' => null
                ];
            })->toArray(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ],
            'statistics' => Message::getStatisticsForUser($userId)
        ];
    }

    /**
     * Mark message as read
     */
    public function markAsRead(int $messageId, int $userId): bool
    {
        $message = Message::forUser($userId)->find($messageId);
        
        if (!$message) {
            return false;
        }
        
        $message->markAsRead();
        
        $this->auditLogService->log([
            'user_id' => $userId,
            'action' => 'message_read',
            'entity_type' => 'message',
            'entity_id' => $messageId,
            'notes' => 'Message marked as read'
        ]);
        
        return true;
    }

    /**
     * Mark all messages as read for a user
     */
    public function markAllAsRead(int $userId): int
    {
        $count = Message::forUser($userId)->unread()->count();

        Message::forUser($userId)->unread()->update([
            'is_read' => true,
        ]);
        
        $this->auditLogService->log([
            'user_id' => $userId,
            'action' => 'messages_mark_all_read',
            'entity_type' => 'message',
            'notes' => "Marked {$count} messages as read"
        ]);
        
        return $count;
    }

    /**
     * Delete message for a user (soft delete)
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = Message::forUser($userId)->find($messageId);
        
        if (!$message) {
            return false;
        }
        
        $message->delete();
        
        $this->auditLogService->log([
            'user_id' => $userId,
            'action' => 'message_deleted',
            'entity_type' => 'message',
            'entity_id' => $messageId,
            'notes' => 'Message deleted by user'
        ]);
        
        return true;
    }

    /**
     * Get unread message count for a user
     */
    public function getUnreadCount(int $userId): int
    {
        return Message::forUser($userId)->unread()->count();
    }

    /**
     * Send bulk messages to multiple users
     */
    public function sendBulkMessage(
        array $userIds,
        string $title,
        string $content,
        int $senderId = null,
        string $type = Message::TYPE_NOTIFICATION,
        string $priority = Message::PRIORITY_NORMAL
    ): int {
        $sent = 0;
        
        foreach ($userIds as $userId) {
            try {
                if ($senderId) {
                    $this->sendMessage($userId, $type, $title, $content, $priority, $senderId);
                } else {
                    $this->sendSystemMessage($userId, $title, $content, $type, $priority);
                }
                $sent++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to send bulk message', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $sent;
    }

    /**
     * @param array<int, array{id:int,email:string|null,username:string|null,name?:string|null}> $adminUsers
     */
    public function sendAdminNotificationBatch(
        array $adminUsers,
        string $type,
        string $title,
        string $content,
        string $priority = Message::PRIORITY_NORMAL
    ): void {
        if (empty($adminUsers)) {
            return;
        }

        $messageRows = [];
        $emailRecipients = [];
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $count = 0;

        foreach ($adminUsers as $admin) {
            $adminId = isset($admin['id']) ? (int) $admin['id'] : 0;
            if ($adminId <= 0) {
                continue;
            }

            $count++;
            $row = [
                'sender_id' => null,
                'receiver_id' => $adminId,
                'title' => $title,
                'content' => $content,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $row['priority'] = in_array($priority, Message::getValidPriorities(), true)
                ? $priority
                : Message::PRIORITY_NORMAL;

            $messageRows[] = $row;

            $email = isset($admin['email']) ? trim((string) $admin['email']) : '';
            if ($email === '') {
                continue;
            }

            $name = null;
            if (isset($admin['username']) && $admin['username'] !== null && $admin['username'] !== '') {
                $name = (string) $admin['username'];
            } elseif (isset($admin['name']) && $admin['name'] !== null && $admin['name'] !== '') {
                $name = (string) $admin['name'];
            }

            if ($name === null) {
                $name = $email;
            }

            $emailRecipients[] = [
                'email' => $email,
                'name' => $name,
            ];
        }

        if (empty($messageRows)) {
            return;
        }

        $insertStart = microtime(true);
        try {
            $this->persistSystemMessagesBulk($messageRows);
        } catch (\Throwable $e) {
            $this->logger->warning('Bulk admin message insert failed, falling back to per-recipient create', [
                'error' => $e->getMessage(),
            ]);
            foreach ($messageRows as $row) {
                try {
                    $createRow = $row;
                    unset($createRow['created_at'], $createRow['updated_at']);
                    Message::create($createRow);
                } catch (\Throwable $inner) {
                    $this->logger->error('Failed to create admin notification message', [
                        'receiver_id' => $row['receiver_id'],
                        'error' => $inner->getMessage(),
                    ]);
                }
            }
        }

        $duration = round((microtime(true) - $insertStart) * 1000.0, 2);
        $this->logger->info('Admin notifications inserted', [
            'recipient_count' => $count,
            'duration_ms' => $duration,
        ]);

        $this->sendBulkLinkedEmail($emailRecipients, $title, $content, $type, $priority);
    }

    protected function persistSystemMessagesBulk(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        Message::query()->insert($rows);
    }

    private function shouldSuppressLinkedEmail(string $type): bool
    {
        $normalized = strtolower(trim($type));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::TYPES_WITH_DEDICATED_EMAIL, true);
    }

    private function maybeSendLinkedEmail(int $receiverId, string $title, string $content, string $type, string $priority): void
    {
        if ($this->emailService === null) {
            return;
        }

        if ($this->shouldSuppressLinkedEmail($type)) {
            return;
        }

        $recipient = $this->resolveEmailRecipient($receiverId);
        if ($recipient === null) {
            return;
        }

        $subject = $this->buildEmailSubject($title, $priority);
        $category = $this->resolveNotificationCategory($type);

        $this->dispatchEmail('message_notification', [
            'receiver_id' => $receiverId,
            'email' => $recipient['email'],
            'name' => $recipient['name'],
            'subject' => $subject,
            'content' => $content,
            'category' => $category,
            'priority' => $priority,
            'type' => $type,
        ]);
    }

    /**
     * @param array<int, array{email:string,name:string|null}> $recipients
     */
    private function sendBulkLinkedEmail(array $recipients, string $title, string $content, string $type, string $priority): void
    {
        if ($this->emailService === null || empty($recipients)) {
            return;
        }

        if ($this->shouldSuppressLinkedEmail($type)) {
            return;
        }

        $formatted = [];
        $seen = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $name = $recipient['name'] ?? null;
            $formatted[] = [
                'email' => $email,
                'name' => ($name !== null && $name !== '') ? (string) $name : null,
            ];
        }

        if (empty($formatted)) {
            return;
        }

        $subject = $this->buildEmailSubject($title, $priority);
        $category = $this->resolveNotificationCategory($type);

        $this->dispatchEmail('message_notification_bulk', [
            'recipients' => $formatted,
            'subject' => $subject,
            'content' => $content,
            'category' => $category,
            'priority' => $priority,
            'type' => $type,
        ]);
    }

    /**
     * @param array<int, array{user_id:int,email:string,name:string}> $recipients
     * @return array{queued:bool,recipient_count:int,error?:string}
     */
    public function queueBroadcastEmail(array $recipients, string $title, string $content, string $priority = Message::PRIORITY_NORMAL, array $context = []): array
    {
        if ($this->emailService === null) {
            return ['queued' => false, 'recipient_count' => 0, 'error' => 'Email service unavailable'];
        }

        $formatted = [];
        $seenEmails = [];
        foreach ($recipients as $recipient) {
            $email = isset($recipient['email']) ? trim((string)$recipient['email']) : '';
            if ($email === '') {
                continue;
            }
            $key = strtolower($email);
            if (isset($seenEmails[$key])) {
                continue;
            }
            $seenEmails[$key] = true;

            $name = isset($recipient['name']) && $recipient['name'] !== ''
                ? (string)$recipient['name']
                : $email;

            $formatted[] = [
                'email' => $email,
                'name' => $name,
                'user_id' => isset($recipient['user_id']) ? (int)$recipient['user_id'] : null,
            ];
        }

        if (empty($formatted)) {
            return ['queued' => false, 'recipient_count' => 0];
        }

        $payload = [
            'recipients' => $formatted,
            'title' => $title,
            'content' => $content,
            'priority' => $priority,
            'subject' => $this->buildEmailSubject($title, $priority),
        ];

        if (!empty($context)) {
            $payload['context'] = $context;
        }

        $this->dispatchEmail('broadcast_announcement', $payload);

        return ['queued' => true, 'recipient_count' => count($formatted)];
    }

    /**
     * Defer email sending until after the response is flushed when running under web SAPI.
     */
    private function dispatchEmail(string $jobType, array $payload): void
    {
        if ($this->emailService === null) {
            return;
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            EmailJobRunner::run($this->emailService, $this->logger, $jobType, $payload);
            return;
        }

        $this->pendingEmailJobs[] = [
            'job_type' => $jobType,
            'payload' => $payload,
        ];

        if (!$this->emailShutdownRegistered) {
            $this->emailShutdownRegistered = true;
            register_shutdown_function([$this, 'flushPendingEmailJobs']);
        }

        if (function_exists('fastcgi_finish_request') && !$this->responseFlushedForAsyncEmail) {
            $this->responseFlushedForAsyncEmail = true;
            try {
                fastcgi_finish_request();
            } catch (\Throwable $ignore) {
                // Some SAPIs do not support this call; safe to ignore.
            }
        }
    }

    /**
     * @internal Called automatically on script shutdown.
     */
    public function flushPendingEmailJobs(): void
    {
        if ($this->emailService === null || empty($this->pendingEmailJobs)) {
            return;
        }

        $jobs = $this->pendingEmailJobs;
        $this->pendingEmailJobs = [];

        if (function_exists('fastcgi_finish_request')) {
            foreach ($jobs as $job) {
                EmailJobRunner::run(
                    $this->emailService,
                    $this->logger,
                    $job['job_type'],
                    $job['payload']
                );
            }
            return;
        }

        $this->spawnBackgroundEmailProcess($jobs);
    }

    /**
     * @param array<int,array{job_type:string,payload:array<string,mixed>}> $jobs
     */
    private function spawnBackgroundEmailProcess(array $jobs): void
    {
        if (empty($jobs)) {
            return;
        }

        if ($this->emailDispatcherScript === null || !is_file($this->emailDispatcherScript)) {
            foreach ($jobs as $job) {
                EmailJobRunner::run($this->emailService, $this->logger, $job['job_type'], $job['payload']);
            }
            return;
        }

        $jobFile = null;

        try {
            $jobData = [
                'jobs' => $jobs,
            ];

            $jobFile = tempnam(sys_get_temp_dir(), 'ct_email_job_');
            if ($jobFile === false) {
                throw new \RuntimeException('Unable to allocate temporary file for email job.');
            }

            file_put_contents($jobFile, json_encode($jobData, JSON_THROW_ON_ERROR));

            $phpBinary = PHP_BINARY ?: 'php';

            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                $escapedBinary = str_replace('"', '""', $phpBinary);
                $escapedScript = str_replace('"', '""', $this->emailDispatcherScript);
                $escapedJobFile = str_replace('"', '""', $jobFile);
                $command = sprintf('"%s" "%s" "%s"', $escapedBinary, $escapedScript, $escapedJobFile);
                $process = @popen('start /B "" ' . $command, 'r');
                if (is_resource($process)) {
                    pclose($process);
                } else {
                    throw new \RuntimeException('Unable to spawn background email process.');
                }
            } else {
                $command = sprintf(
                    '%s %s %s',
                    escapeshellarg($phpBinary),
                    escapeshellarg($this->emailDispatcherScript),
                    escapeshellarg($jobFile)
                );
                $result = @exec($command . ' > /dev/null 2>&1 &');
                if ($result === false) {
                    throw new \RuntimeException('Unable to spawn background email process.');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Falling back to synchronous email dispatch', [
                'job_count' => count($jobs),
                'error' => $e->getMessage(),
            ]);

            if (is_string($jobFile) && $jobFile !== '' && is_file($jobFile)) {
                @unlink($jobFile);
            }

            foreach ($jobs as $job) {
                EmailJobRunner::run($this->emailService, $this->logger, $job['job_type'], $job['payload']);
            }
        }
    }

    /**
     * Resolve email recipient details for notifications.
     *
     * @return array{email:string,name:string}|null
     */
    private function resolveEmailRecipient(int $userId, ?string $fallbackEmail = null, ?string $fallbackName = null): ?array
    {
        if ($userId > 0 && $this->userResolver !== null) {
            try {
                $resolved = call_user_func($this->userResolver, $userId);
                if ($resolved instanceof User && !empty($resolved->email)) {
                    $name = $resolved->getDisplayName() ?: (string) $resolved->email;
                    return [
                        'email' => (string) $resolved->email,
                        'name' => $name,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to resolve receiver for email notification', [
                    'receiver_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($userId > 0) {
            try {
                $user = User::query()->find($userId);
                if ($user instanceof User && !empty($user->email)) {
                    $name = $user->getDisplayName() ?: (string) $user->email;
                    return [
                        'email' => (string) $user->email,
                        'name' => $name,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to load user for email notification', [
                    'receiver_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($fallbackEmail !== null && $fallbackEmail !== '') {
            return [
                'email' => $fallbackEmail,
                'name' => $fallbackName && $fallbackName !== '' ? $fallbackName : $fallbackEmail,
            ];
        }

        return null;
    }

    public function sendExchangeConfirmationEmailToUser(
        int $userId,
        string $productName,
        int $quantity,
        float $pointsSpent,
        ?string $fallbackEmail = null,
        ?string $fallbackName = null
    ): void {
        if ($this->emailService === null) {
            return;
        }

        $recipient = $this->resolveEmailRecipient($userId, $fallbackEmail, $fallbackName);
        if ($recipient === null) {
            return;
        }

        $this->dispatchEmail('exchange_confirmation', [
            'user_id' => $userId,
            'email' => $recipient['email'],
            'name' => $recipient['name'],
            'product_name' => $productName,
            'quantity' => $quantity,
            'points_spent' => $pointsSpent,
        ]);
    }

    public function sendExchangeStatusUpdateEmailToUser(
        int $userId,
        string $productName,
        string $status,
        ?string $trackingNumber = null,
        ?string $adminNotes = null,
        ?string $fallbackEmail = null,
        ?string $fallbackName = null
    ): void {
        if ($this->emailService === null) {
            return;
        }

        $recipient = $this->resolveEmailRecipient($userId, $fallbackEmail, $fallbackName);
        if ($recipient === null) {
            return;
        }

        $noteParts = [];
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $noteParts[] = 'Tracking number: ' . $trackingNumber;
        }
        if ($adminNotes !== null && $adminNotes !== '') {
            $noteParts[] = $adminNotes;
        }
        $combinedNotes = implode("\n", $noteParts);

        $this->dispatchEmail('exchange_status_update', [
            'user_id' => $userId,
            'email' => $recipient['email'],
            'name' => $recipient['name'],
            'product_name' => $productName,
            'status' => $status,
            'notes' => $combinedNotes,
        ]);
    }

    private function resolveNotificationCategory(string $type): string
    {
        $key = strtolower(trim($type));
        if ($key === '') {
            return NotificationPreferenceService::CATEGORY_SYSTEM;
        }

        if (isset(self::TYPE_CATEGORY_MAP[$key])) {
            return self::TYPE_CATEGORY_MAP[$key];
        }

        return NotificationPreferenceService::CATEGORY_SYSTEM;
    }

    private function buildEmailSubject(string $title, string $priority): string
    {
        $prefix = '';
        switch ($priority) {
            case Message::PRIORITY_URGENT:
                $prefix = '[URGENT] ';
                break;
            case Message::PRIORITY_HIGH:
                $prefix = '[HIGH] ';
                break;
        }

        return $prefix . $title;
    }

    private function sendActivityApprovedEmail(?User $user, $activity, $transaction): void
    {
        if ($this->emailService === null || !$user || empty($user->email)) {
            return;
        }

        $activityName = '';
        if (is_object($activity) && method_exists($activity, 'getCombinedName')) {
            $activityName = (string) $activity->getCombinedName();
        } elseif (is_array($activity)) {
            $activityName = (string) ($activity['name'] ?? '');
        } elseif (is_object($activity) && isset($activity->name)) {
            $activityName = (string) $activity->name;
        }
        $points = is_array($transaction) ? ($transaction['points'] ?? 0) : ($transaction->points ?? 0);

        $this->dispatchEmail('activity_approved_notification', [
            'user_id' => $user->id ?? null,
            'email' => (string) $user->email,
            'name' => $user->getDisplayName() ?: (string) $user->email,
            'activity_name' => (string) $activityName,
            'points' => (float) $points,
        ]);
    }

    private function sendActivityRejectedEmail(?User $user, $activity, ?string $reason): void
    {
        if ($this->emailService === null || !$user || empty($user->email)) {
            return;
        }

        $activityName = '';
        if (is_object($activity) && method_exists($activity, 'getCombinedName')) {
            $activityName = (string) $activity->getCombinedName();
        } elseif (is_array($activity)) {
            $activityName = (string) ($activity['name'] ?? '');
        } elseif (is_object($activity) && isset($activity->name)) {
            $activityName = (string) $activity->name;
        }
        $reasonText = $reason ?? 'See in-app notification for details.';

        $this->dispatchEmail('activity_rejected_notification', [
            'user_id' => $user->id ?? null,
            'email' => (string) $user->email,
            'name' => $user->getDisplayName() ?: (string) $user->email,
            'activity_name' => (string) $activityName,
            'reason' => (string) $reasonText,
        ]);
    }

    private function sendExchangeConfirmationEmail(?User $user, $product, int $quantity, float $pointsSpent): void
    {
        if ($this->emailService === null || !$user || empty($user->email)) {
            return;
        }

        $productName = '';
        if (is_object($product)) {
            $productName = (string) ($product->name ?? '');
        } elseif (is_array($product)) {
            $productName = (string) ($product['name'] ?? '');
        }

        try {
            $this->emailService->sendExchangeConfirmation(
                (string) $user->email,
                $user->getDisplayName() ?: (string) $user->email,
                $productName,
                $quantity,
                $pointsSpent
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send exchange confirmation email', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


