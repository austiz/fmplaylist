<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sound_bytes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('filename');
            $table->string('category', 20)->default('jingle'); // jingle|shoutout|drop|id
            $table->string('storage_path', 500)->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('file_size')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('needs_pi_download')->default(false);
            $table->boolean('pi_delete_requested')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sound_bytes');
    }
};
