<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The transmitters: who they are, what they have been told to do, what they hold
 * on disk, and the networks they may join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pi_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label')->default('Raspberry Pi');
            $table->string('token_hash')->index();

            // Telemetry, all of it reported on the heartbeat.
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('pi_status')->default('offline');
            $table->string('pi_mode')->default('normal');
            $table->string('pi_ip')->nullable();
            $table->boolean('pi_skip_next')->default(false);
            $table->string('pi_daemon_hash')->nullable();
            // bigInteger, not integer: a 32 GB card overflows a signed 32-bit column.
            $table->unsignedBigInteger('disk_free_bytes')->nullable();
            $table->unsignedBigInteger('disk_total_bytes')->nullable();

            // Health. fm_running is what distinguishes "the daemon is alive" from
            // "the station is actually on the air".
            $table->boolean('pi_fm_running')->nullable();
            $table->unsignedInteger('pi_queue_depth')->nullable();
            $table->string('pi_last_error')->nullable();
            $table->string('pi_last_update_status')->nullable();
            $table->timestamp('pi_last_update_at')->nullable();

            $table->timestamps();
        });

        Schema::create('pi_commands', function (Blueprint $table) {
            $table->id();
            // Addressed to one device, not stored as a station-wide flag: a flag is
            // consumed by whichever device heartbeats first, so the second device on
            // a station never sees it.
            $table->foreignId('pi_token_id')->constrained()->cascadeOnDelete();
            $table->string('command');
            // A string, not json: every payload is a bare filename -- the
            // emergency announcement to play. MySQL rejects `evac.wav` as
            // invalid json, so this column type only ever worked on sqlite.
            // Length is explicit to match the request's max:255 rather than
            // inherit the app's 191 default and truncate what validation let by.
            $table->string('payload', 255)->nullable();
            $table->string('status')->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();

            $table->index(['pi_token_id', 'status']);
        });

        Schema::create('device_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pi_token_id')->constrained()->cascadeOnDelete();
            // Not a foreign key: this records what a device reported holding, which
            // has to outlive the asset row so the delete can be confirmed.
            $table->string('media_type');
            $table->unsignedBigInteger('media_id');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['pi_token_id', 'media_type', 'media_id']);
            $table->index(['media_type', 'media_id']);
        });

        Schema::create('wifi_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('ssid');
            $table->string('password')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['station_id', 'ssid']);
            $table->index(['station_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wifi_networks');
        Schema::dropIfExists('device_downloads');
        Schema::dropIfExists('pi_commands');
        Schema::dropIfExists('pi_tokens');
    }
};
