<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('now_playing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('queue_item_id')->nullable()->constrained('queue_items')->nullOnDelete();
            $table->enum('type', ['song', 'station_id'])->default('song');
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('now_playing');
    }
};
