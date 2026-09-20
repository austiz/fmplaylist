<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A station is one transmitted programme: its own library, queue, settings and
 * devices. Everything else in this schema hangs off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // One station has to exist before anything else can belong to one: an empty
        // install has a working queue, settings page and public site from the start,
        // and `Station::defaultId()` always has an answer.
        DB::table('stations')->insert([
            'name' => 'Station 1',
            'slug' => 'station-1',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
