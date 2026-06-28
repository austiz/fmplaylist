<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->string('pi_status', 20)->default('offline')->after('last_seen_at');
            $table->string('pi_mode', 30)->default('normal')->after('pi_status');
            $table->string('pi_ip', 45)->nullable()->after('pi_mode');
        });
    }

    public function down(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropColumn(['pi_status', 'pi_mode', 'pi_ip']);
        });
    }
};
