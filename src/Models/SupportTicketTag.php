<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketTag extends Model
{
    protected $table = 'support_ticket_tags';

    protected $fillable = [
        'slug',
        'name',
        'color',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'bool',
    ];
}
