<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'id',
        'user_id',
        'actor_type',
        'action',
        'operation_category',
        'operation_subtype',
        'status',
        'affected_table',
        'affected_id',
        'request_id',
        'data',
        'old_data',
        'new_data',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'data' => 'array',
        'old_data' => 'array',
        'new_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
