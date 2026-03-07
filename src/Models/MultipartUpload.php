<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;

class MultipartUpload extends Model
{
    protected $table = 'multipart_uploads';

    protected $fillable = [
        'upload_id',
        'file_path',
        'sha256',
        'user_id',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'int',
        'expires_at' => 'datetime',
    ];
}
