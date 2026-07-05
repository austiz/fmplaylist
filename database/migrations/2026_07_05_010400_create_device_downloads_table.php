<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pi_token_id')->constrained()->cascadeOnDelete();
            $table->enum('media_type', ['song', 'commercial', 'sound_byte']);
            $table->unsignedBigInteger('media_id');
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->unique(['pi_token_id', 'media_type', 'media_id']);
            $table->index(['media_type', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_downloads');
    }
};
