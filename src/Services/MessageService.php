<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\Message;
use CarbonTrack\Models\User;
use Monolog\Logger;

class MessageService
{
    private Logger $logger;
    private AuditLogService $auditLogService;

    public function __construct(Logger $logger, AuditLogService $auditLogService)
    {
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
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
        ?int $senderId = null
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
        ?int $relatedEntityId = null
    ): Message {
        return Message::createSystemMessage(
            $receiverId,
            $title,
            $content,
            $type,
            $priority,
            $relatedEntityType,
            $relatedEntityId
        );
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

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_APPROVAL,
            Message::PRIORITY_HIGH,
            null,
            null
        );
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

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_REJECTION,
            Message::PRIORITY_HIGH,
            null,
            null
        );
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

        return $this->sendSystemMessage(
            $user->id,
            $title,
            $content,
            Message::TYPE_EXCHANGE,
            Message::PRIORITY_NORMAL,
            null,
            null
        );
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
        
        foreach ($adminUsers as $admin) {
            $this->sendSystemMessage(
                $admin->id,
                $title,
                $content,
                Message::TYPE_NOTIFICATION,
                Message::PRIORITY_HIGH,
                null,
                null
            );
        }
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
}

