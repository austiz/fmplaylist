<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Production hit "Numeric value out of range" writing disk_total_bytes for a Pi with
 * ~122GB free (131,593,297,920 bytes) — well within BIGINT UNSIGNED range, so whatever
 * type actually landed on that column isn't what the original migration declared.
 * Force it via a raw ALTER rather than guessing why; MySQL-only since SQLite has no
 * fixed column-width enforcement (nothing to fix there, and MODIFY COLUMN syntax
 * doesn't exist in SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE pi_tokens MODIFY disk_free_bytes BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE pi_tokens MODIFY disk_total_bytes BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // No-op: the prior state's exact type is unknown/wrong, nothing safe to revert to.
    }
};
