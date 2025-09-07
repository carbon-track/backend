<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Simple migration script to create a files metadata table for deduplication.
 * Run manually by including from a maintenance script (project currently uses raw SQL files mostly).
 */
return new class {
    public function up(): void
    {
        if (Capsule::schema()->hasTable('files')) {
            return; // idempotent
        }
        Capsule::schema()->create('files', function ($table) {
            $table->bigIncrements('id');
            $table->string('sha256', 64)->index();
            $table->string('file_path', 512)->unique();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('original_name', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedInteger('reference_count')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Capsule::schema()->hasTable('files')) {
            Capsule::schema()->drop('files');
        }
    }
};
