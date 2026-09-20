<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pi_commands.payload` was declared json but has only ever held a bare
 * filename -- the emergency announcement a station is told to play.
 *
 * sqlite stores anything in a json column, so the whole test suite passed
 * locally while every emergency broadcast failed on MySQL with "Invalid JSON
 * text" -- in CI, and in production, which is also MySQL.
 *
 * The baseline now declares the right type for a fresh database; this is for
 * the databases that already ran it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_commands', function (Blueprint $table) {
            $table->string('payload', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pi_commands', function (Blueprint $table) {
            $table->json('payload')->nullable()->change();
        });
    }
};
