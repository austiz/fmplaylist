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
        Schema::create('pi_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64);
            $table->string('label')->default('Raspberry Pi');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('token_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pi_tokens');
    }
};
