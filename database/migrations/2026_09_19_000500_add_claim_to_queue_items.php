<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lease on the head of the queue, so two transmitters on one station do not
 * both go away with the same song.
 *
 * The queue item stays `pending` until it is reported as played, so before this
 * nothing stopped a second device polling thirty seconds later from being handed
 * the item the first one was already cueing up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->foreignId('claimed_by_pi_token_id')->nullable()->after('status')
                ->constrained('pi_tokens')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by_pi_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_pi_token_id');
            $table->dropColumn('claimed_at');
        });
    }
};
