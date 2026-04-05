<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketTagAssignment extends Model
{
    protected $table = 'support_ticket_tag_assignments';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'tag_id',
        'source_type',
        'rule_id',
        'created_at',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'tag_id' => 'int',
        'rule_id' => 'int',
    ];
}
