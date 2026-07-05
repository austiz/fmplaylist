<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('now_playing', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->unique()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('now_playing', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
        });
    }
};
