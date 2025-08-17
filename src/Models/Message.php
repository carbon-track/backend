<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    protected $table = 'messages';
    
    protected $fillable = [
        'sender_id',
        'receiver_id',
        'title',
        'content',
        'type',
        'is_read',
        'read_at',
        'related_entity_type',
        'related_entity_id',
        'priority'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $dates = ['deleted_at'];

    public $timestamps = true;

    // Message types
    const TYPE_SYSTEM = 'system';
    const TYPE_NOTIFICATION = 'notification';
    const TYPE_APPROVAL = 'approval';
    const TYPE_REJECTION = 'rejection';
    const TYPE_EXCHANGE = 'exchange';
    const TYPE_WELCOME = 'welcome';
    const TYPE_REMINDER = 'reminder';

    // Priority levels
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * Get the sender of the message
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get the receiver of the message
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Scope to get unread messages
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope to get read messages
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Scope to get messages by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get messages by priority
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to get messages for a specific user (received)
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('receiver_id', $userId);
    }

    /**
     * Scope to get messages sent by a specific user
     */
    public function scopeFromUser($query, int $userId)
    {
        return $query->where('sender_id', $userId);
    }

    /**
     * Scope to get recent messages (within specified days)
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>', now()->subDays($days));
    }

    /**
     * Mark message as read
     */
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now()
            ]);
        }
    }

    /**
     * Mark message as unread
     */
    public function markAsUnread(): void
    {
        if ($this->is_read) {
            $this->update([
                'is_read' => false,
                'read_at' => null
            ]);
        }
    }

    /**
     * Check if message is high priority
     */
    public function isHighPriority(): bool
    {
        return in_array($this->priority, [self::PRIORITY_HIGH, self::PRIORITY_URGENT]);
    }

    /**
     * Check if message is system message
     */
    public function isSystemMessage(): bool
    {
        return $this->type === self::TYPE_SYSTEM || $this->sender_id === null;
    }

    /**
     * Get message age in human readable format
     */
    public function getAgeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get related entity (if any)
     */
    public function getRelatedEntity()
    {
        if (!$this->related_entity_type || !$this->related_entity_id) {
            return null;
        }

        switch ($this->related_entity_type) {
            case 'points_transaction':
                return PointsTransaction::find($this->related_entity_id);
            case 'exchange_transaction':
                return ExchangeTransaction::find($this->related_entity_id);
            case 'product':
                return Product::find($this->related_entity_id);
            case 'user':
                return User::find($this->related_entity_id);
            default:
                return null;
        }
    }

    /**
     * Create a system message
     */
    public static function createSystemMessage(
        int $receiverId,
        string $title,
        string $content,
        string $type = self::TYPE_SYSTEM,
        string $priority = self::PRIORITY_NORMAL,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null
    ): self {
        return static::create([
            'sender_id' => null, // System message
            'receiver_id' => $receiverId,
            'title' => $title,
            'content' => $content,
            'type' => $type,
            'priority' => $priority,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id' => $relatedEntityId,
            'is_read' => false
        ]);
    }

    /**
     * Create a notification message
     */
    public static function createNotification(
        int $receiverId,
        string $title,
        string $content,
        string $priority = self::PRIORITY_NORMAL,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null
    ): self {
        return static::createSystemMessage(
            $receiverId,
            $title,
            $content,
            self::TYPE_NOTIFICATION,
            $priority,
            $relatedEntityType,
            $relatedEntityId
        );
    }

    /**
     * Create an approval notification
     */
    public static function createApprovalNotification(
        int $receiverId,
        string $title,
        string $content,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null
    ): self {
        return static::createSystemMessage(
            $receiverId,
            $title,
            $content,
            self::TYPE_APPROVAL,
            self::PRIORITY_HIGH,
            $relatedEntityType,
            $relatedEntityId
        );
    }

    /**
     * Create a rejection notification
     */
    public static function createRejectionNotification(
        int $receiverId,
        string $title,
        string $content,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null
    ): self {
        return static::createSystemMessage(
            $receiverId,
            $title,
            $content,
            self::TYPE_REJECTION,
            self::PRIORITY_HIGH,
            $relatedEntityType,
            $relatedEntityId
        );
    }

    /**
     * Create an exchange notification
     */
    public static function createExchangeNotification(
        int $receiverId,
        string $title,
        string $content,
        ?string $relatedEntityType = null,
        ?int $relatedEntityId = null
    ): self {
        return static::createSystemMessage(
            $receiverId,
            $title,
            $content,
            self::TYPE_EXCHANGE,
            self::PRIORITY_NORMAL,
            $relatedEntityType,
            $relatedEntityId
        );
    }

    /**
     * Create a welcome message
     */
    public static function createWelcomeMessage(int $receiverId): self
    {
        $title = '欢迎加入CarbonTrack! / Welcome to CarbonTrack!';
        $content = "亲爱的用户，欢迎加入CarbonTrack碳减排追踪平台！\n\n" .
                  "在这里，您可以：\n" .
                  "• 记录您的碳减排活动\n" .
                  "• 获得碳减排积分\n" .
                  "• 兑换环保商品\n" .
                  "• 查看您的环保贡献\n\n" .
                  "让我们一起为地球环保贡献力量！\n\n" .
                  "Dear user, welcome to CarbonTrack!\n\n" .
                  "Here you can:\n" .
                  "• Record your carbon reduction activities\n" .
                  "• Earn carbon reduction points\n" .
                  "• Exchange for eco-friendly products\n" .
                  "• View your environmental contributions\n\n" .
                  "Let's work together for a greener planet!";

        return static::createSystemMessage(
            $receiverId,
            $title,
            $content,
            self::TYPE_WELCOME,
            self::PRIORITY_NORMAL
        );
    }

    /**
     * Get message statistics for a user
     */
    public static function getStatisticsForUser(int $userId): array
    {
        $total = static::forUser($userId)->count();
        $unread = static::forUser($userId)->unread()->count();
        $read = static::forUser($userId)->read()->count();
        
        $byType = static::forUser($userId)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $byPriority = static::forUser($userId)
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $read,
            'by_type' => $byType,
            'by_priority' => $byPriority
        ];
    }

    /**
     * Clean up old read messages
     */
    public static function cleanupOldMessages(int $daysToKeep = 90): int
    {
        return static::where('is_read', true)
            ->where('created_at', '<', now()->subDays($daysToKeep))
            ->where('priority', '!=', self::PRIORITY_URGENT)
            ->delete();
    }

    /**
     * Get valid message types
     */
    public static function getValidTypes(): array
    {
        return [
            self::TYPE_SYSTEM,
            self::TYPE_NOTIFICATION,
            self::TYPE_APPROVAL,
            self::TYPE_REJECTION,
            self::TYPE_EXCHANGE,
            self::TYPE_WELCOME,
            self::TYPE_REMINDER
        ];
    }

    /**
     * Get valid priority levels
     */
    public static function getValidPriorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT
        ];
    }
}

