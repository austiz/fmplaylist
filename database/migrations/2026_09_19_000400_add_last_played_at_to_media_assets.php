<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalizes "when was this last on air" onto the asset itself.
 *
 * Autofill picks the least recently played songs, and it answered that with a
 * correlated subquery over `queue_items` -- one aggregate per candidate row, over an
 * unindexed `played_at`, on every device poll. The fact is cheap to maintain at the
 * one place a play is recorded, so it is stored rather than derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->timestamp('last_played_at')->nullable()->after('play_count');

            // Autofill reads it as "songs, active, least recently played first",
            // and nulls -- never played -- sort first on both drivers.
            $table->index(['type', 'last_played_at']);
        });

        DB::table('media_assets')->update([
            'last_played_at' => DB::raw(
                "(SELECT MAX(played_at) FROM queue_items
                  WHERE queue_items.media_asset_id = media_assets.id
                    AND queue_items.status = 'played')"
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['type', 'last_played_at']);
            $table->dropColumn('last_played_at');
        });
    }
};
