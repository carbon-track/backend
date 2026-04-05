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
        'last_replied_at',
        'last_reply_by_role',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'assigned_to' => 'int',
    ];
}
