<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->boolean('pi_skip_next')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropColumn('pi_skip_next');
        });
    }
};
