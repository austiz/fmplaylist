<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daemon already reports these on every heartbeat; the server validated them
 * away and dropped them. Persisting them is what makes the devices page able to
 * show whether a Pi is actually transmitting, rather than only whether its
 * process is alive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->boolean('pi_fm_running')->nullable();
            $table->integer('pi_queue_depth')->nullable();
            $table->string('pi_last_error', 255)->nullable();
            $table->string('pi_last_update_status', 20)->nullable();
            $table->timestamp('pi_last_update_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pi_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'pi_fm_running',
                'pi_queue_depth',
                'pi_last_error',
                'pi_last_update_status',
                'pi_last_update_at',
            ]);
        });
    }
};
