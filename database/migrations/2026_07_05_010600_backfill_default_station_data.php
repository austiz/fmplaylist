<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ((int) DB::table('stations')->count() === 0) {
            DB::table('stations')->insert([
                'name' => 'Station 1',
                'slug' => 'station-1',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $stationId = DB::table('stations')->where('is_default', true)->value('id')
            ?? DB::table('stations')->orderBy('id')->value('id');

        foreach (['settings', 'queue_items', 'now_playing', 'pi_tokens'] as $table) {
            DB::table($table)->whereNull('station_id')->update(['station_id' => $stationId]);
        }
    }

    public function down(): void
    {
        // Data backfill only — leaving station_id populated on rollback is harmless
        // and safer than guessing which rows to null back out.
    }
};
