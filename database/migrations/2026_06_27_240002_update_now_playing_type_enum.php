<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't enforce enum constraints — only MySQL needs altering
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE now_playing MODIFY COLUMN `type`
                 ENUM('song','station_id','commercial','sound_byte')
                 NOT NULL DEFAULT 'song'"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE now_playing MODIFY COLUMN `type`
                 ENUM('song','station_id')
                 NOT NULL DEFAULT 'song'"
            );
        }
    }
};
