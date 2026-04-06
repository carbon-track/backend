<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketAutomationRule extends Model
{
    protected $table = 'support_ticket_automation_rules';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'sort_order',
        'match_category',
        'match_priority',
        'match_weekdays',
        'match_time_start',
        'match_time_end',
        'timezone',
        'assign_to',
        'score_boost',
        'required_agent_level',
        'skill_hints_json',
        'add_tag_ids',
        'stop_processing',
        'trigger_count',
        'last_triggered_at',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'sort_order' => 'int',
        'assign_to' => 'int',
        'score_boost' => 'float',
        'required_agent_level' => 'int',
        'stop_processing' => 'bool',
        'trigger_count' => 'int',
    ];
}
