<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketFeedback extends Model
{
    protected $table = 'support_ticket_feedback';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'rated_user_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'user_id' => 'int',
        'rated_user_id' => 'int',
        'rating' => 'int',
    ];
}
