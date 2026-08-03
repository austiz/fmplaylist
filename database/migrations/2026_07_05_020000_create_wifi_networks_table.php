<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wifi_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('ssid', 100);
            // Nullable rather than empty-string for open networks, so "no password"
            // is distinguishable from "password not yet entered".
            $table->string('password', 128)->nullable();
            // Lower number = tried first. Mirrors commercials.rotation_order.
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['station_id', 'ssid']);
            $table->index(['station_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wifi_networks');
    }
};
