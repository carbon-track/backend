<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\File;
use Illuminate\Database\Capsule\Manager as DB;
class FileMetadataService
{
    private const PUBLIC_READABLE_ROOTS = ['products', 'badges', 'avatars'];

    public function findBySha256(string $sha256): ?File
    {
        return File::where('sha256',$sha256)->orderByDesc('id')->first();
    }

    public function findByFilePath(string $filePath): ?File
    {
        return File::where('file_path', $filePath)->orderByDesc('id')->first();
    }

    public function isPubliclyReadablePath(string $filePath): bool
    {
        return in_array($this->extractRootDirectory($filePath), self::PUBLIC_READABLE_ROOTS, true);
    }

    public function extractRootDirectory(string $filePath): string
    {
        $normalized = ltrim(trim($filePath), '/');
        if ($normalized === '') {
            return '';
        }

        $segments = explode('/', $normalized);
        return strtolower($segments[0] ?? '');
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
