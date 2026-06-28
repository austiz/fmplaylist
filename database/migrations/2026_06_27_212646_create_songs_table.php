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
        Schema::create('songs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('artist')->default('');
            $table->string('filename')->unique();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->index('title');
            $table->index('artist');
            $table->index('available');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
