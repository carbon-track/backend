<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketTransferRequest extends Model
{
    protected $table = 'support_ticket_transfer_requests';

    protected $fillable = [
        'ticket_id',
        'requested_by',
        'from_assignee',
        'to_assignee',
        'reason',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'requested_by' => 'int',
        'from_assignee' => 'int',
        'to_assignee' => 'int',
        'reviewed_by' => 'int',
    ];
}
