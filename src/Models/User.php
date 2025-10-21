<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';
    
    protected $fillable = [
        'id',
        'username',
        'email',
        'password',
        'role',
        'status',
        'points',
        'school',
        'location',
        'is_admin',
        'lastlgn',
        'notification_email_mask'
    ];

    protected $hidden = [
        'password'
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'is_admin' => 'boolean',
        'lastlgn' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'notification_email_mask' => 'integer'
    ];

    protected $dates = ['deleted_at'];

    public $timestamps = true;

    /**
     * Create user from array data (for testing)
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        
        // Set attributes directly for testing
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable)) {
                $this->attributes[$key] = $value;
            }
        }
    }

    /**
     * Get user ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get username
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Get email
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    // real_name 字段已废弃，不再提供访问方法


    /**
     * Get role
     */
    public function getRole(): string
    {
        return $this->role ?? ($this->is_admin ? 'admin' : 'user');
    }

    /**
     * Get status
     */
    public function getStatus(): string
    {
        return $this->status ?? 'active';
    }

    /**
     * Get points
     */
    public function getPoints(): float
    {
        return (float) $this->points;
    }

    /**
     * Convert to array (excluding sensitive data)
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['password']);
        // 安全隐藏已弃用或潜在敏感字段（数据库仍然可能存在列，但接口不暴露）
        unset($array['real_name'], $array['class_name']);
        return $array;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->getRole() === 'admin';
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->getStatus() === 'active';
    }

    /**
     * Check if user has sufficient points
     */
    public function hasSufficientPoints(float $requiredPoints): bool
    {
        return $this->getPoints() >= $requiredPoints;
    }

    /**
     * Add points to user
     */
    public function addPoints(float $points): void
    {
        if ($points > 0) {
            $this->points = $this->getPoints() + $points;
        }
    }

    /**
     * Subtract points from user
     */
    public function subtractPoints(float $points): bool
    {
        if ($this->hasSufficientPoints($points)) {
            $this->points = $this->getPoints() - $points;
            return true;
        }
        return false;
    }

    /**
     * Get display name
     */
    public function getDisplayName(): string
    {
        return $this->getUsername();
    }

    /**
     * Validate user data
     */
    public function isValid(): bool
    {
        return empty($this->getValidationErrors());
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        if (empty($this->username)) {
            $errors[] = 'Username is required';
        }

        if (empty($this->email)) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        $validRoles = ['user', 'admin', 'moderator'];
        if (!in_array($this->getRole(), $validRoles)) {
            $errors[] = 'Invalid role';
        }

        $validStatuses = ['active', 'inactive', 'suspended'];
        if (!in_array($this->getStatus(), $validStatuses)) {
            $errors[] = 'Invalid status';
        }

        return $errors;
    }

    /**
     * Get the user's points transactions
     */
    public function pointsTransactions()
    {
        return $this->hasMany(PointsTransaction::class, 'uid');
    }

    /**
     * Get the user's exchange transactions
     */
    public function exchangeTransactions()
    {
        return $this->hasMany(ExchangeTransaction::class, 'user_id');
    }

    /**
     * Get messages sent by this user
     */
    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Get messages received by this user
     */
    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    /**
     * Get the user's school
     */
    public function schoolInfo()
    {
        return $this->belongsTo(School::class, 'school', 'name');
    }

    /**
     * Get audit logs for this user
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    /**
     * Scope to get active users only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get admin users only
     */
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true);
    }

    /**
     * Update last login time
     */
    public function updateLastLogin(): void
    {
        $this->update(['lastlgn' => new DateTimeImmutable()]);
    }

    /**
     * Get unread messages count
     */
    public function getUnreadMessagesCount(): int
    {
        return $this->receivedMessages()
            ->where('is_read', false)
            ->whereNull('deleted_at')
            ->count();
    }
}
