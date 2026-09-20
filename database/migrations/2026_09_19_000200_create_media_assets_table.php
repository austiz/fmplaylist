<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folds `songs`, `commercials` and `sound_bytes` into one `media_assets` table.
 *
 * Song ids are carried over verbatim so `queue_items` and `now_playing` keep
 * pointing at the right row and only need their column renamed. Commercials and
 * sound bytes get fresh ids, so their `device_downloads` rows are remapped as
 * they are copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('filename')->unique();
            $table->string('storage_path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('active')->default(true);

            // Sound bytes only.
            $table->string('category', 20)->nullable();
            $table->string('rds_ps', 8)->nullable();

            // Commercials only.
            $table->unsignedInteger('rotation_order')->default(0);
            $table->unsignedInteger('play_count')->default(0);

            $table->boolean('needs_pi_download')->default(false);
            $table->boolean('pi_delete_requested')->default(false);
            $table->timestamps();

            // Every listing is "this type, active, newest first" or "this type by title".
            $table->index(['type', 'active']);
            $table->index(['type', 'title']);
            $table->index('pi_delete_requested');
        });

        $this->copySongs();
        $this->copyWithRemap('commercials', 'commercial');
        $this->copyWithRemap('sound_bytes', 'sound_byte');

        $this->repointReferences();

        Schema::dropIfExists('songs');
        Schema::dropIfExists('commercials');
        Schema::dropIfExists('sound_bytes');
    }

    /** Ids are preserved, so nothing referencing a song needs remapping. */
    private function copySongs(): void
    {
        foreach (DB::table('songs')->orderBy('id')->cursor() as $song) {
            DB::table('media_assets')->insert([
                'id' => $song->id,
                'type' => 'song',
                'title' => $song->title,
                'artist' => $song->artist,
                'filename' => $song->filename,
                'storage_path' => $song->storage_path,
                'file_size' => $song->file_size,
                'duration_seconds' => $song->duration_seconds,
                'active' => $song->available,
                'needs_pi_download' => $song->needs_pi_download,
                'pi_delete_requested' => $song->pi_delete_requested,
                'created_at' => $song->created_at,
                'updated_at' => $song->updated_at,
            ]);
        }
    }

    private function copyWithRemap(string $table, string $type): void
    {
        foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
            $newId = DB::table('media_assets')->insertGetId([
                'type' => $type,
                'title' => $row->title,
                'artist' => null,
                'filename' => $row->filename,
                'storage_path' => $row->storage_path,
                'file_size' => $row->file_size,
                'duration_seconds' => $row->duration_seconds,
                'active' => $row->active,
                'category' => $row->category ?? null,
                'rds_ps' => $row->rds_ps ?? null,
                'rotation_order' => $row->rotation_order ?? 0,
                'play_count' => $row->play_count ?? 0,
                'needs_pi_download' => $row->needs_pi_download,
                'pi_delete_requested' => $row->pi_delete_requested,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            DB::table('device_downloads')
                ->where('media_type', $type)
                ->where('media_id', $row->id)
                ->update(['media_id' => $newId]);
        }
    }

    private function repointReferences(): void
    {
        Schema::table('queue_items', function (Blueprint $table) {
            $table->dropForeign(['song_id']);
        });
        Schema::table('queue_items', function (Blueprint $table) {
            $table->renameColumn('song_id', 'media_asset_id');
        });
        Schema::table('queue_items', function (Blueprint $table) {
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->cascadeOnDelete();
        });

        Schema::table('now_playing', function (Blueprint $table) {
            $table->dropForeign(['song_id']);
        });
        Schema::table('now_playing', function (Blueprint $table) {
            $table->renameColumn('song_id', 'media_asset_id');
        });
        Schema::table('now_playing', function (Blueprint $table) {
            $table->foreign('media_asset_id')->references('id')->on('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // One-way: the three source tables are dropped above and Phase 5 rebaselines
        // the schema anyway. Roll back by re-running migrations from scratch.
        throw new RuntimeException('create_media_assets_table cannot be reversed; use migrate:fresh.');
    }
};
