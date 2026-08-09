<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pi_commands', function (Blueprint $table) {
            $table->id();
            // Per-device, not per-station. Station-scoped setting flags were
            // consumed by whichever Pi heartbeated first, so a second Pi on the
            // same station never saw the command at all.
            $table->foreignId('pi_token_id')->constrained()->cascadeOnDelete();
            $table->string('command', 32);
            $table->text('payload')->nullable();
            $table->string('status', 16)->default('queued'); // queued|sent|acked|failed
            $table->text('result')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pi_token_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pi_commands');
    }
};
