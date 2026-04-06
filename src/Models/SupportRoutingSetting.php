<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportRoutingSetting extends Model
{
    protected $table = 'support_routing_settings';

    protected $fillable = [
        'ai_enabled',
        'ai_timeout_ms',
        'due_soon_minutes',
        'weights_json',
        'fallback_json',
        'defaults_json',
    ];

    protected $casts = [
        'ai_enabled' => 'bool',
        'ai_timeout_ms' => 'int',
        'due_soon_minutes' => 'int',
    ];
}
