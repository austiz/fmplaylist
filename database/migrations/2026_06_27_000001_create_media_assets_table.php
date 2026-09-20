<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Songs, commercials and sound bytes in one table.
 *
 * They were three, and they differed only in scheduling policy -- which lives in
 * QueueService, not in the schema. Everything the three had in common (storage,
 * duration, device download tracking, delete-after-the-device-confirms) was
 * written out three times, and had already drifted apart in places.
 *
 * `type` is a plain string cast to a PHP enum rather than a MySQL ENUM, so the
 * SQLite the tests run on and the MySQL production runs on agree on the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('filename')->unique();
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('active')->default(true);

            // Sound bytes only: which slot it belongs to, and the RDS text to show.
            $table->string('category')->nullable();
            $table->string('rds_ps')->nullable();

            // Commercials only: position in the round-robin.
            $table->unsignedInteger('rotation_order')->default(0);

            $table->unsignedInteger('play_count')->default(0);
            // Denormalized from queue_items, written when a segment reaches the air.
            // Autofill orders by it, and deriving it was a correlated MAX() per
            // candidate row over an unindexed column.
            $table->timestamp('last_played_at')->nullable();

            $table->boolean('needs_pi_download')->default(false);
            $table->boolean('pi_delete_requested')->default(false);

            $table->timestamps();

            $table->index(['type', 'active']);
            $table->index(['type', 'title']);
            $table->index(['type', 'last_played_at']);
            $table->index(['station_id', 'type', 'active']);
            $table->index('pi_delete_requested');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
