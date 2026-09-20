<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finishes the multi-station split.
 *
 * `media_assets` and `chat_messages` were the two tables every station shared, so
 * one station's library and chat showed up on all of them. `device_downloads` is
 * deliberately left alone: it already reaches a station through `pi_token_id`, and
 * a second copy of that answer is a second thing to keep in sync.
 *
 * `now_playing.station_id` was nullable *and* unique while being the de-facto
 * primary key, so a NULL row could sit alongside every real one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = DB::table('stations')->orderBy('id')->value('id');

        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        if ($default !== null) {
            DB::table('media_assets')->whereNull('station_id')->update(['station_id' => $default]);
            DB::table('chat_messages')->whereNull('station_id')->update(['station_id' => $default]);
            DB::table('now_playing')->whereNull('station_id')->update(['station_id' => $default]);
        }

        Schema::table('media_assets', function (Blueprint $table) {
            $table->index(['station_id', 'type', 'active']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index(['station_id', 'created_at']);
        });

        Schema::table('now_playing', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('now_playing', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->change();
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['station_id', 'type', 'active']);
            $table->dropConstrainedForeignId('station_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['station_id', 'created_at']);
            $table->dropConstrainedForeignId('station_id');
        });
    }
};
