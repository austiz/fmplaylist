<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            // Dashboard/history charts filter on these two and nothing else indexes them.
            $table->index('played_at');
            $table->index('created_at');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('pi_tokens', function (Blueprint $table) {
            // "Is any device online?" scans every token on each /api/pi-status hit.
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropIndex(['played_at']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
        });
    }
};
