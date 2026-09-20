<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `station_ids` held RDS "Station ID" audio idents. The feature shipped as sound
 * bytes with category `id` instead, so the table never gained a model, a route or
 * a reader — only a comment on App\Models\Station warning people off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('station_ids');
    }

    public function down(): void
    {
        Schema::create('station_ids', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('filename');
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }
};
