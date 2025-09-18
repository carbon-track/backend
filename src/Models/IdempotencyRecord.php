<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    protected $table = 'idempotency_records';
    
    protected $fillable = [
        'idempotency_key',
        'user_id',
        'request_method',
        'request_uri',
        'request_body',
        'response_status',
        'response_body',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'response_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public $timestamps = true;

    /**
     * Get the user that made the request
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to find records by idempotency key
     */
    public function scopeByIdempotencyKey($query, string $key)
    {
        return $query->where('idempotency_key', $key);
    }

    /**
     * Scope to find recent records (within specified hours)
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('created_at', '>', \Illuminate\Support\Carbon::now()->subHours($hours));
    }

    /**
     * Clean up old idempotency records
     */
    public static function cleanup(int $daysToKeep = 7): int
    {
        return static::where('created_at', '<', \Illuminate\Support\Carbon::now()->subDays($daysToKeep))->delete();
    }
}

