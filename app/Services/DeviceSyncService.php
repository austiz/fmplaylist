<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\DeviceDownload;
use App\Models\PiToken;
use App\Models\Song;
use App\Models\SoundByte;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Per-device (not per-station) download/delete tracking.
 *
 * Songs/commercials/sound bytes are a shared library, but each physical Pi has its own
 * SD card and must independently track what it has actually downloaded — a media row's
 * `needs_pi_download` flag being false only means SOME device downloaded it, not this one.
 * `needs_pi_download` is kept only as a cosmetic "someone has downloaded this" badge for
 * the admin Sounds page — it is NOT authoritative for sync, `device_downloads` is.
 */
class DeviceSyncService
{
    /** @return Collection<int, array<string, mixed>> */
    public function pendingDownloadsFor(PiToken $token): Collection
    {
        $downloaded = $this->downloadedIds($token);

        $pending = collect();

        $pending = $pending->merge(
            Song::available()->get(['id', 'filename', 'title', 'artist', 'storage_path'])
                ->reject(fn ($s) => in_array($s->id, $downloaded['song'], true))
                ->map(fn ($s) => [
                    'type' => 'song',
                    'item_id' => $s->id,
                    'filename' => $s->filename,
                    'title' => $s->title,
                    'download_url' => url(Storage::disk('public')->url($s->storage_path)),
                ])
        );

        $pending = $pending->merge(
            Commercial::active()->get(['id', 'filename', 'title', 'storage_path'])
                ->reject(fn ($c) => in_array($c->id, $downloaded['commercial'], true))
                ->map(fn ($c) => [
                    'type' => 'commercial',
                    'item_id' => $c->id,
                    'filename' => $c->filename,
                    'title' => $c->title,
                    'download_url' => url(Storage::disk('public')->url($c->storage_path)),
                ])
        );

        $pending = $pending->merge(
            SoundByte::active()->get(['id', 'filename', 'title', 'storage_path'])
                ->reject(fn ($sb) => in_array($sb->id, $downloaded['sound_byte'], true))
                ->map(fn ($sb) => [
                    'type' => 'sound_byte',
                    'item_id' => $sb->id,
                    'filename' => $sb->filename,
                    'title' => $sb->title,
                    'download_url' => url(Storage::disk('public')->url($sb->storage_path)),
                ])
        );

        return $pending->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function pendingDeletesFor(PiToken $token): Collection
    {
        $downloaded = $this->downloadedIds($token);

        $deletes = collect();

        $deletes = $deletes->merge(
            Song::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->filter(fn ($s) => in_array($s->id, $downloaded['song'], true))
                ->map(fn ($s) => ['type' => 'song', 'item_id' => $s->id, 'filename' => $s->filename])
        );
        $deletes = $deletes->merge(
            Commercial::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->filter(fn ($c) => in_array($c->id, $downloaded['commercial'], true))
                ->map(fn ($c) => ['type' => 'commercial', 'item_id' => $c->id, 'filename' => $c->filename])
        );
        $deletes = $deletes->merge(
            SoundByte::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->filter(fn ($sb) => in_array($sb->id, $downloaded['sound_byte'], true))
                ->map(fn ($sb) => ['type' => 'sound_byte', 'item_id' => $sb->id, 'filename' => $sb->filename])
        );

        return $deletes->values();
    }

    public function recordDownload(PiToken $token, string $type, int $itemId): void
    {
        DeviceDownload::updateOrCreate(
            ['pi_token_id' => $token->id, 'media_type' => $type, 'media_id' => $itemId],
            ['downloaded_at' => now()]
        );

        // Cosmetic only — see class docblock. Not read by pendingDownloadsFor().
        match ($type) {
            'commercial' => Commercial::where('id', $itemId)->update(['needs_pi_download' => false]),
            'sound_byte' => SoundByte::where('id', $itemId)->update(['needs_pi_download' => false]),
            default => Song::where('id', $itemId)->update(['needs_pi_download' => false]),
        };
    }

    public function recordDelete(PiToken $token, string $type, int $itemId): void
    {
        DeviceDownload::where('pi_token_id', $token->id)
            ->where('media_type', $type)
            ->where('media_id', $itemId)
            ->delete();

        $stillHeld = DeviceDownload::where('media_type', $type)->where('media_id', $itemId)->exists();

        if (! $stillHeld) {
            match ($type) {
                'commercial' => Commercial::where('id', $itemId)->delete(),
                'sound_byte' => SoundByte::where('id', $itemId)->delete(),
                default => Song::where('id', $itemId)->delete(),
            };
        }
    }

    /** @return array{done: int, total: int} */
    public function downloadProgress(PiToken $token): array
    {
        $downloaded = $this->downloadedIds($token);

        $total = Song::available()->count() + Commercial::active()->count() + SoundByte::active()->count();
        $done = count($downloaded['song']) + count($downloaded['commercial']) + count($downloaded['sound_byte']);

        return ['done' => min($done, $total), 'total' => $total];
    }

    /** @return array{song: array<int, int>, commercial: array<int, int>, sound_byte: array<int, int>} */
    private function downloadedIds(PiToken $token): array
    {
        $rows = DeviceDownload::where('pi_token_id', $token->id)->get(['media_type', 'media_id']);

        return [
            'song' => $rows->where('media_type', 'song')->pluck('media_id')->all(),
            'commercial' => $rows->where('media_type', 'commercial')->pluck('media_id')->all(),
            'sound_byte' => $rows->where('media_type', 'sound_byte')->pluck('media_id')->all(),
        ];
    }
}
