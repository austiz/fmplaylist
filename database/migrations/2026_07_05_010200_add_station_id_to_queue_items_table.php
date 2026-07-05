<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->dropIndex(['status', 'position']);
            $table->index(['station_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropIndex(['station_id', 'status', 'position']);
            $table->index(['status', 'position']);

            $table->dropConstrainedForeignId('station_id');
        });
    }
};
