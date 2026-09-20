<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is queued, and what is on the air.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->unsignedInteger('position');
            $table->string('status')->default('pending');

            // A lease on the head of the queue. Several devices can transmit one
            // station, and an item stays pending until it is reported as played, so
            // without this two of them are handed the same song.
            $table->foreignId('claimed_by_pi_token_id')->nullable()
                ->constrained('pi_tokens')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamp('played_at')->nullable();
            $table->timestamps();

            // Every query filters the station first, so that leads the index.
            $table->index(['station_id', 'status', 'position']);
            $table->index('played_at');
            $table->index('created_at');
        });

        Schema::create('now_playing', function (Blueprint $table) {
            $table->id();
            // One row per station, enforced: this is the de-facto primary key, and a
            // nullable one let a NULL row coexist with the real ones.
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('queue_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('song');
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->unique('station_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('now_playing');
        Schema::dropIfExists('queue_items');
    }
};
