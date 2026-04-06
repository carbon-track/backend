<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'user_id',
        'subject',
        'category',
        'status',
        'priority',
        'assigned_to',
        'assignment_source',
        'assigned_rule_id',
        'assignment_locked',
        'first_support_response_at',
        'first_response_due_at',
        'resolution_due_at',
        'sla_status',
        'escalation_level',
        'last_routing_run_id',
        'last_replied_at',
        'last_reply_by_role',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'assigned_to' => 'int',
        'assigned_rule_id' => 'int',
        'assignment_locked' => 'bool',
        'escalation_level' => 'int',
        'last_routing_run_id' => 'int',
    ];
}
