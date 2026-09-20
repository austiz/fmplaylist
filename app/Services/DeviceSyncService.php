<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\DeviceDownload;
use App\Models\PiToken;
use App\Models\Song;
use App\Models\SoundByte;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Per-device (not per-station) download/delete tracking.
 *
 * Songs/commercials/sound bytes are a shared library, but each physical Pi has its own
 * SD card and must independently track what it has actually downloaded — a media row's
 * `needs_pi_download` flag being false only means SOME device downloaded it, not this one.
 *
 * `needs_pi_download` is a vestigial flag: it defaults to false, is global rather than
 * per-device, and is never reset when a device is re-flashed or its token is deleted. It
 * must not be used to answer "is this on the Pi?" — the admin Sounds badge did exactly
 * that and reported "On Pi" for media no device had ever downloaded. `device_downloads`
 * is authoritative; use activeDeviceCount()/holderCounts()/isHeldByAnyDevice().
 */
class DeviceSyncService
{
    /** @return Collection<int, array<string, mixed>> */
    public function pendingDownloadsFor(PiToken $token): Collection
    {
        $downloaded = $this->downloadedIds($token);

        $pending = collect();

        $pending = $pending->merge(
            Song::available()->whereNotNull('storage_path')->get(['id', 'filename', 'title', 'artist', 'storage_path'])
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
            Commercial::active()->whereNotNull('storage_path')->get(['id', 'filename', 'title', 'storage_path'])
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
            SoundByte::active()->whereNotNull('storage_path')->get(['id', 'filename', 'title', 'storage_path'])
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

    /**
     * Devices that have actually checked in at least once.
     *
     * A token that was generated but never provisioned isn't a real device — counting it
     * would leave every media row permanently "partially synced".
     */
    public function activeDeviceCount(): int
    {
        return PiToken::whereNotNull('last_seen_at')->count();
    }

    /**
     * How many real devices hold each of the given media ids.
     *
     * This is the authoritative answer to "is it on the Pi?" — `needs_pi_download` is a
     * global cosmetic flag and cannot answer it (see class docblock).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int> media_id => holder count (missing key means zero)
     */
    public function holderCounts(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DeviceDownload::query()
            ->where('media_type', $type)
            ->whereIn('media_id', $ids)
            ->whereIn('pi_token_id', PiToken::whereNotNull('last_seen_at')->select('id'))
            ->selectRaw('media_id, count(distinct pi_token_id) as holders')
            ->groupBy('media_id')
            ->pluck('holders', 'media_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** Whether any device currently holds this item — the safe check before a hard delete. */
    public function isHeldByAnyDevice(string $type, int $itemId): bool
    {
        return DeviceDownload::where('media_type', $type)
            ->where('media_id', $itemId)
            ->exists();
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

    /**
     * Legacy rows can predate per-device download tracking. If no device claims a
     * delete-requested item, no Pi can ever receive or confirm that delete, so the
     * row sits pending forever.
     *
     * This is repair work for historical data, not part of the sync protocol, so it
     * runs on a schedule (see routes/console.php) rather than inside the 30s device
     * poll, where it cost three full table scans plus one EXISTS per row per beat.
     *
     * @return int rows purged
     */
    public function purgeOrphanedDeleteRequests(): int
    {
        return $this->purgeOrphans(Song::query(), 'song')
            + $this->purgeOrphans(Commercial::query(), 'commercial')
            + $this->purgeOrphans(SoundByte::query(), 'sound_byte');
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function purgeOrphans(Builder $query, string $type): int
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where('pi_delete_requested', true)
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from('device_downloads')
                ->where('device_downloads.media_type', $type)
                ->whereColumn('device_downloads.media_id', $table.'.id'))
            ->delete();
    }
}
