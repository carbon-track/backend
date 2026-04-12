<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class CronRun extends Model
{
    protected $table = 'cron_runs';
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_key',
        'trigger_source',
        'request_id',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'result_json',
        'error_message',
    ];

    protected $casts = [
        'duration_ms' => 'int',
    ];
}
