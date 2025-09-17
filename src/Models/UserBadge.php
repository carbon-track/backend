<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    protected $table = 'user_badges';

    protected $fillable = [
        'user_id',
        'badge_id',
        'status',
        'awarded_at',
        'awarded_by',
        'revoked_at',
        'revoked_by',
        'source',
        'notes',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'awarded_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function badge()
    {
        return $this->belongsTo(AchievementBadge::class, 'badge_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeAwarded($query)
    {
        return $query->where('status', 'awarded');
    }

    public function isAwarded(): bool
    {
        return $this->status === 'awarded';
    }
}
