<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('storage_path', 500)->nullable()->after('available');
            $table->boolean('needs_pi_download')->default(false)->after('storage_path');
            $table->boolean('pi_delete_requested')->default(false)->after('needs_pi_download');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn(['storage_path', 'needs_pi_download', 'pi_delete_requested']);
        });
    }
};
