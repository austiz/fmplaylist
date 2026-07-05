<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * settings.key is currently the primary key (no autoincrement id), and doctrine/dbal
     * isn't installed, so Schema::table(...)->change() isn't available. Recreate the table
     * instead — portable on both MySQL (prod) and SQLite (tests).
     */
    public function up(): void
    {
        Schema::create('settings_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained('stations')->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'key']);
        });

        DB::table('settings')->orderBy('key')->each(function ($row) {
            DB::table('settings_new')->insert([
                'station_id' => null,
                'key' => $row->key,
                'value' => $row->value,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('settings');
        Schema::rename('settings_new', 'settings');
    }

    public function down(): void
    {
        Schema::create('settings_old', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->whereNull('station_id')->orderBy('key')->each(function ($row) {
            DB::table('settings_old')->insert([
                'key' => $row->key,
                'value' => $row->value,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('settings');
        Schema::rename('settings_old', 'settings');
    }
};
