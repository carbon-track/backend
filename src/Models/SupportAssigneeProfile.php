<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportAssigneeProfile extends Model
{
    protected $table = 'support_assignee_profiles';

    protected $fillable = [
        'user_id',
        'level',
        'skills_json',
        'languages_json',
        'max_active_tickets',
        'is_auto_assignable',
        'weight_overrides_json',
        'status',
    ];

    protected $casts = [
        'user_id' => 'int',
        'level' => 'int',
        'max_active_tickets' => 'int',
        'is_auto_assignable' => 'bool',
    ];
}
