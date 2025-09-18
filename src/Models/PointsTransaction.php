<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class PointsTransaction extends Model
{
    protected $table = 'points_transactions';

    protected $fillable = [
        'id',
        'uid',
        'points_change',
        'description',
        'meta',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'points_change' => 'float',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
