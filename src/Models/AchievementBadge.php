<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AchievementBadge extends Model
{
    use SoftDeletes;

    protected $table = 'achievement_badges';

    protected $fillable = [
        'uuid',
        'code',
        'name_zh',
        'name_en',
        'description_zh',
        'description_en',
        'icon_path',
        'icon_thumbnail_path',
        'is_active',
        'sort_order',
        'auto_grant_enabled',
        'auto_grant_criteria',
        'message_title_zh',
        'message_title_en',
        'message_body_zh',
        'message_body_en',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_grant_enabled' => 'boolean',
        'auto_grant_criteria' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function userBadges()
    {
        return $this->hasMany(UserBadge::class, 'badge_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }
}
