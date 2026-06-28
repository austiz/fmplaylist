<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('filename');
            $table->string('storage_path', 500)->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('file_size')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('rotation_order')->default(0);
            $table->integer('play_count')->default(0);
            $table->boolean('needs_pi_download')->default(false);
            $table->boolean('pi_delete_requested')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercials');
    }
};
