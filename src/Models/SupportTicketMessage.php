<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    protected $table = 'support_ticket_messages';

    protected $fillable = [
        'ticket_id',
        'sender_id',
        'sender_role',
        'sender_name',
        'body',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'sender_id' => 'int',
    ];
}
