<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\File;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;

class FileMetadataService
{
    public function __construct(private Logger $logger) {}

    public function findBySha256(string $sha256): ?File
    {
        return File::where('sha256',$sha256)->orderByDesc('id')->first();
    }

    public function createRecord(array $data): File
    {
        return File::create($data);
    }

    public function incrementReference(File $file): File
    {
        $file->reference_count += 1;
        $file->save();
        return $file;
    }

    /**
     * Create new or increment reference if duplicate sha256 exists.
     * Returns [file: File, duplicated: bool]
     */
    public function createOrIncrement(array $data): array
    {
        $sha256 = $data['sha256'] ?? null;
        if (!$sha256) {
            return ['file' => $this->createRecord($data), 'duplicated' => false];
        }
        return DB::connection()->transaction(function() use ($sha256,$data){
            $existing = File::where('sha256',$sha256)->lockForUpdate()->first();
            if ($existing) {
                $existing->reference_count += 1;
                $existing->save();
                return ['file'=>$existing,'duplicated'=>true];
            }
            $new = File::create($data);
            return ['file'=>$new,'duplicated'=>false];
        });
    }
}
