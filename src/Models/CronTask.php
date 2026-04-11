<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class CronTask extends Model
{
    protected $table = 'cron_tasks';

    protected $fillable = [
        'task_key',
        'task_name',
        'description',
        'interval_minutes',
        'enabled',
        'next_run_at',
        'last_started_at',
        'last_finished_at',
        'last_status',
        'last_error',
        'last_duration_ms',
        'consecutive_failures',
        'lock_token',
        'locked_at',
        'settings_json',
    ];

    protected $casts = [
        'interval_minutes' => 'int',
        'enabled' => 'bool',
        'last_duration_ms' => 'int',
        'consecutive_failures' => 'int',
    ];
}
