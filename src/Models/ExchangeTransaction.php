<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeTransaction extends Model
{
    protected $table = 'point_exchanges';

    protected $fillable = [
        'id',
        'user_id',
        'product_id',
        'quantity',
        'points_cost',
        'status',
        'meta',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'quantity' => 'int',
        'points_cost' => 'float',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
