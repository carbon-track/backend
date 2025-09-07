<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected $table = 'files';
    protected $fillable = [
        'sha256','file_path','mime_type','size','original_name','user_id','reference_count'
    ];

    protected $casts = [
        'size' => 'int',
        'user_id' => 'int',
        'reference_count' => 'int'
    ];
}
