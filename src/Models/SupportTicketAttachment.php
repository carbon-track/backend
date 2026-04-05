<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketAttachment extends Model
{
    protected $table = 'support_ticket_attachments';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'message_id',
        'file_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'entity_type',
        'created_at',
    ];

    protected $casts = [
        'ticket_id' => 'int',
        'message_id' => 'int',
        'file_id' => 'int',
        'size' => 'int',
    ];
}
