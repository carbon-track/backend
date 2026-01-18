<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class UserUsageStats extends Model
{
    protected $table = 'user_usage_stats';
    
    // Composite primary key handling in Eloquent is tricky.
    // We'll rely on query builders mostly, or set incrementing=false
    public $incrementing = false;
    protected $primaryKey = ['user_id', 'resource_key'];
    public $timestamps = false; // We manage last_updated_at manually

    protected $fillable = [
        'user_id',
        'resource_key',
        'counter',
        'last_updated_at',
        'reset_at'
    ];

    protected $casts = [
        'counter' => 'decimal:4',
        'last_updated_at' => 'datetime',
        'reset_at' => 'datetime'
    ];
}
