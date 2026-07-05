<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->string('pi_daemon_hash', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropColumn('pi_daemon_hash');
        });
    }
};
