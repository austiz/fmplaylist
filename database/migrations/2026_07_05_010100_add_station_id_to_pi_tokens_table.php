<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('disk_free_bytes')->nullable()->after('pi_daemon_hash');
            $table->unsignedBigInteger('disk_total_bytes')->nullable()->after('disk_free_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
            $table->dropColumn(['disk_free_bytes', 'disk_total_bytes']);
        });
    }
};
