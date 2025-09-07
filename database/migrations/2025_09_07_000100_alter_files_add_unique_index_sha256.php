<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Add unique index on sha256 to optimize dedup lookups.
 * Raw SQL snippet (MySQL): ALTER TABLE `files` ADD UNIQUE KEY `uniq_files_sha256` (`sha256`);
 */
return new class {
    public function up(): void
    {
        if (!Capsule::schema()->hasTable('files')) { return; }
        // Some drivers (like SQLite) ignore adding duplicate indexes gracefully.
        try {
            Capsule::schema()->table('files', function($table){
                // Laravel schema builder lacks conditional unique existence check; rely on catch.
                $table->unique('sha256','uniq_files_sha256');
            });
        } catch (\Throwable $e) {
            // Ignore if already exists.
        }
    }
    public function down(): void
    {
        if (!Capsule::schema()->hasTable('files')) { return; }
        try {
            Capsule::schema()->table('files', function($table){
                $table->dropUnique('uniq_files_sha256');
            });
        } catch (\Throwable $e) {}
    }
};
