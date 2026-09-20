<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\PiToken;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Per-device (not per-station) download/delete tracking.
 *
 * Media is a shared library, but each physical Pi has its own SD card and must
 * independently track what it has actually downloaded — a media row's
 * `needs_pi_download` flag being false only means SOME device downloaded it, not this one.
 *
 * `needs_pi_download` is a vestigial flag: it defaults to false, is global rather than
 * per-device, and is never reset when a device is re-flashed or its token is deleted. It
 * must not be used to answer "is this on the Pi?" — the admin Sounds badge did exactly
 * that and reported "On Pi" for media no device had ever downloaded. `device_downloads`
 * is authoritative; use activeDeviceCount()/holderCounts()/isHeldByAnyDevice().
 *
 * `device_downloads.media_type` is now redundant with `media_assets.type`, but it stays:
 * the Pi protocol is keyed on `(type, item_id)` pairs and daemons already in the field
 * report them back that way.
 */
class DeviceSyncService
{
    /** @return Collection<int, array{type: string, item_id: int, filename: string, title: string, download_url: string}> */
    public function pendingDownloadsFor(PiToken $token): Collection
    {
        $assets = MediaAsset::query()
            ->active()
            ->whereNotNull('storage_path')
            ->whereNotIn('id', $this->downloadedIds($token))
            ->get(['id', 'type', 'filename', 'title', 'storage_path']);

        return $this->inTypeOrder($assets)
            ->map(fn (MediaAsset $a) => [
                'type' => $a->type->value,
                'item_id' => $a->id,
                'filename' => $a->filename,
                'title' => $a->title,
                'download_url' => url(Storage::disk('public')->url($a->storage_path)),
            ])
            ->values();
    }

    /** @return Collection<int, array{type: string, item_id: int, filename: string}> */
    public function pendingDeletesFor(PiToken $token): Collection
    {
        $assets = MediaAsset::query()
            ->where('pi_delete_requested', true)
            ->whereIn('id', $this->downloadedIds($token))
            ->get(['id', 'type', 'filename']);

        return $this->inTypeOrder($assets)
            ->map(fn (MediaAsset $a) => [
                'type' => $a->type->value,
                'item_id' => $a->id,
                'filename' => $a->filename,
            ])
            ->values();
    }

    /**
     * Songs first, then commercials, then sound bytes — the order the daemon has always
     * received, back when these were three tables queried in turn.
     *
     * @param  Collection<int, MediaAsset>  $assets
     * @return Collection<int, MediaAsset>
     */
    private function inTypeOrder(Collection $assets): Collection
    {
        $order = array_flip(array_column(MediaType::cases(), 'value'));

        return $assets->sortBy(fn (MediaAsset $a) => $order[$a->type->value])->values();
    }

    public function recordDownload(PiToken $token, string $type, int $itemId): void
    {
        DeviceDownload::updateOrCreate(
            ['pi_token_id' => $token->id, 'media_type' => $type, 'media_id' => $itemId],
            ['downloaded_at' => now()]
        );

        // Cosmetic only — see class docblock. Not read by pendingDownloadsFor().
        MediaAsset::where('id', $itemId)->where('type', $type)->update(['needs_pi_download' => false]);
    }

    public function recordDelete(PiToken $token, string $type, int $itemId): void
    {
        DeviceDownload::where('pi_token_id', $token->id)
            ->where('media_type', $type)
            ->where('media_id', $itemId)
            ->delete();

        $stillHeld = DeviceDownload::where('media_type', $type)->where('media_id', $itemId)->exists();

        if (! $stillHeld) {
            MediaAsset::where('id', $itemId)->where('type', $type)->delete();
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

    /**
     * How far through the library each of the given devices is.
     *
     * Takes the whole device list rather than one device at a time: the total is the
     * same for every one of them, and the per-device tallies come back in one grouped
     * query, so the devices page costs two queries instead of two per row.
     *
     * @param  array<int, int>  $tokenIds
     * @return array{total: int, done: array<int, int>} done is token_id => count, missing key means zero
     */
    public function downloadProgressFor(array $tokenIds): array
    {
        $total = MediaAsset::query()->active()->count();

        if ($tokenIds === []) {
            return ['total' => $total, 'done' => []];
        }

        $done = DeviceDownload::query()
            ->whereIn('pi_token_id', $tokenIds)
            ->selectRaw('pi_token_id, count(*) as held')
            ->groupBy('pi_token_id')
            ->pluck('held', 'pi_token_id')
            ->map(fn ($held) => min((int) $held, $total))
            ->all();

        return ['total' => $total, 'done' => $done];
    }

    /**
     * The media ids this device holds.
     *
     * Flat, not keyed by type: since the three tables became one, `media_id` is a
     * `media_assets` primary key and is unique across types on its own. That lets the
     * two callers above push their filtering into the query instead of loading the
     * whole library and rejecting most of it in PHP.
     *
     * @return Collection<int, int>
     */
    private function downloadedIds(PiToken $token): Collection
    {
        return DeviceDownload::where('pi_token_id', $token->id)->pluck('media_id');
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
        return MediaAsset::query()
            ->where('pi_delete_requested', true)
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from('device_downloads')
                ->whereColumn('device_downloads.media_type', 'media_assets.type')
                ->whereColumn('device_downloads.media_id', 'media_assets.id'))
            ->delete();
    }
}
