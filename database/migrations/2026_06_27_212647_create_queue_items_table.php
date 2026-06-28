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
        Schema::create('queue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('song_id')->constrained()->cascadeOnDelete();
            $table->string('requested_by_name')->nullable();
            $table->unsignedInteger('position');
            $table->enum('status', ['pending', 'playing', 'played', 'skipped'])->default('pending');
            $table->timestamp('played_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_items');
    }
};
