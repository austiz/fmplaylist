<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Enums\SettingKey;
use App\Http\Resources\NowPlayingResource;
use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Station;
use App\Support\CurrentStation;
use App\Support\StationSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every public method here takes the station it acts on, and runs its body through
 * `CurrentStation::as()` so the station-scoped models filter themselves to it --
 * including relation loads, which a `where('station_id', …)` on the outer query
 * never reached. Writes still name `station_id` explicitly: a read that loses its
 * scope returns nothing, which is loud, while a write that loses it misfiles a row
 * onto the default station, which is silent.
 */
class QueueService
{
    /**
     * Locks the station row for the duration of the enclosing transaction, serializing
     * concurrent position-assignment (two listeners requesting at once, or a request
     * racing the Pi-driven auto-fill/now-playing update). Locking the station row — not
     * just the matching queue rows — also creates a mutex when the queue is currently
     * empty, when there'd otherwise be no row for `lockForUpdate()` to lock.
     */
    private function lockStation(int $stationId): void
    {
        Station::where('id', $stationId)->lockForUpdate()->first();
    }

    /**
     * Read-only peek at the next $limit pending songs — no state changes.
     * Used by the Pi to pre-decode upcoming songs before they're needed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peekUpcoming(int $stationId, int $limit = 3): array
    {
        return CurrentStation::as($stationId, fn () => QueueItem::with('mediaAsset')
            ->pending()
            ->skip(1)           // skip 'next' (already returned by getNextForPi)
            ->take($limit)
            ->get()
            ->map(fn (QueueItem $item) => [
                'queue_item_id' => $item->id,
                'song' => $this->songPayload($item->mediaAsset),
            ])
            ->all());
    }

    /** @return array<string, mixed> */
    public function getNextForPi(int $stationId): array
    {
        return CurrentStation::as($stationId, fn () => $this->nextForPi($stationId));
    }

    /** @return array<string, mixed> */
    private function nextForPi(int $stationId): array
    {
        $this->autoFillQueue($stationId);

        // Six reads, one query. This runs on the Pi's 30-second poll.
        $settings = StationSettings::for($stationId);

        // ── Commercial scheduling ─────────────────────────────────────────
        $commercial = null;
        $forcedCommercialId = $settings->int(SettingKey::ForceCommercialId);
        if ($forcedCommercialId) {
            $commercial = MediaAsset::query()->ofType(MediaType::Commercial)->active()->find($forcedCommercialId);
        }
        if (! $commercial) {
            $comInterval = $settings->int(SettingKey::CommercialInterval);
            $songsSinceCom = $settings->int(SettingKey::SongsSinceLastCommercial);
            if ($comInterval > 0 && $songsSinceCom >= $comInterval) {
                $commercial = MediaAsset::nextCommercialInRotation($stationId);
            }
        }

        // ── Sound byte scheduling ─────────────────────────────────────────
        $soundByte = null;
        $forcedSoundByteId = $settings->int(SettingKey::ForceSoundByteId);
        if ($forcedSoundByteId) {
            $soundByte = MediaAsset::query()->ofType(MediaType::SoundByte)->active()->find($forcedSoundByteId);
        }
        if (! $soundByte) {
            $sbInterval = $settings->int(SettingKey::SoundByteInterval);
            $songsSinceSb = $settings->int(SettingKey::SongsSinceLastSoundByte);
            if ($sbInterval > 0 && $songsSinceSb >= $sbInterval) {
                $soundByte = MediaAsset::query()->ofType(MediaType::SoundByte)->active()->inRandomOrder()->first();
            }
        }

        // ── Next queued song ──────────────────────────────────────────────
        $next = QueueItem::with('mediaAsset')->pending()->first();

        return [
            'commercial' => $commercial ? [
                'id' => $commercial->id,
                'filename' => $commercial->filename,
                'title' => $commercial->title,
            ] : null,
            'sound_byte' => $soundByte ? [
                'id' => $soundByte->id,
                'filename' => $soundByte->filename,
                'title' => $soundByte->title,
                'category' => $soundByte->category,
                'rds_ps' => $soundByte->rds_ps,
            ] : null,
            'next' => $next ? [
                'queue_item_id' => $next->id,
                'requested_by_name' => $next->requested_by_name,
                'song' => $this->songPayload($next->mediaAsset),
            ] : null,
        ];
    }

    /**
     * The shape the Pi daemon expects for a queued song — unchanged by the media
     * unification, so the protocol is unaffected.
     *
     * @return array<string, mixed>
     */
    private function songPayload(MediaAsset $song): array
    {
        return [
            'id' => $song->id,
            'title' => $song->title,
            'artist' => $song->artist,
            'filename' => $song->filename,
            'duration_seconds' => $song->duration_seconds,
        ];
    }

    /**
     * Record what a device just put on air.
     *
     * Reports arrive more than once for the same segment: the daemon fires them from a
     * detached thread and retries, and a station can carry more than one transmitter,
     * each reporting the same song. So this is idempotent on the segment's identity --
     * a repeat is acknowledged and dropped. Without that, the second report retired the
     * song that had *just* started and advanced the commercial and sound-byte counters
     * a second time, so the rotation drifted a little on every retry.
     */
    public function markNowPlaying(int $stationId, string $type, ?int $queueItemId, ?string $filename, ?int $itemId = null): void
    {
        CurrentStation::as($stationId, fn () => DB::transaction(function () use ($stationId, $type, $queueItemId, $filename, $itemId) {
            $this->lockStation($stationId);

            $song = null;
            if ($filename && $type === 'song') {
                $song = MediaAsset::query()->songs()->where('filename', $filename)->first();
            }

            // Commercials and sound bytes are rows in `media_assets` too now, so the
            // column can name them and the segment has an identity to compare.
            $mediaId = $type === 'song' ? $song?->id : $itemId;

            $current = NowPlaying::query()->forStation($stationId)->first();

            if ($current
                && $current->type === $type
                && $current->queue_item_id === $queueItemId
                && $current->media_asset_id === $mediaId) {
                return;
            }

            // By id, not by status: a blanket `where('status', 'playing')` also retired
            // the item this report is about, which is what made a replay move the queue
            // backwards.
            QueueItem::where('status', 'playing')
                ->when($queueItemId !== null, fn ($q) => $q->where('id', '!=', $queueItemId))
                ->update([
                    'status' => 'played',
                    'played_at' => now(),
                ]);

            // One write for all three types, here rather than in the per-type handlers:
            // this is the single place a segment is known to have reached the air, and
            // the idempotency check above already ran, so a retried report cannot move
            // a song's turn back down the autofill order.
            if ($mediaId) {
                MediaAsset::where('id', $mediaId)->update(['last_played_at' => now()]);
            }

            // One write for all three types, here rather than in the per-type handlers:
            // this is the single place a segment is known to have reached the air, and
            // the idempotency check above already ran, so a retried report cannot move
            // a song's turn back down the autofill order.
            if ($mediaId) {
                MediaAsset::where('id', $mediaId)->update(['last_played_at' => now()]);
            }

            $nowPlaying = NowPlaying::updateOrCreate(
                ['station_id' => $stationId],
                [
                    'media_asset_id' => $mediaId,
                    'queue_item_id' => $queueItemId,
                    'type' => $type,
                    'started_at' => now(),
                ]
            );

            match ($type) {
                'commercial' => $this->onCommercialPlayed($stationId, $itemId),
                'sound_byte' => $this->onSoundBytePlayed($stationId),
                default => $this->onSongPlayed($stationId, $queueItemId), // 'song'
            };

            // Push SSE events. The frame is the same shape the REST endpoints
            // return, so a listener sees no difference between the two transports.
            $nowPlaying->setRelation('mediaAsset', $song);
            $npPayload = (new NowPlayingResource($nowPlaying))->resolve();

            Cache::put("sse.now_playing.{$stationId}", $npPayload, 3600);
            $this->bumpQueueVersion($stationId);
        }));
    }

    private function autoFillQueue(int $stationId, ?int $target = null): void
    {
        $target ??= (int) config('fm.autofill_target');

        DB::transaction(function () use ($stationId, $target) {
            $this->lockStation($stationId);

            $pendingCount = QueueItem::where('status', 'pending')->count();
            $needed = $target - $pendingCount;

            if ($needed <= 0) {
                return;
            }

            // Exclude songs already pending or currently playing. Both the queue and the
            // library are scoped to this station, so there is nothing else to exclude.
            $excludeIds = QueueItem::whereIn('status', ['pending', 'playing'])
                ->pluck('media_asset_id');

            $songs = MediaAsset::query()->songs()->active()
                ->whereNotIn('id', $excludeIds)
                // Least recently played first, nulls -- never played -- ahead of them.
                // This used to be a correlated MAX() over `queue_items` per candidate
                // row; `last_played_at` is maintained in markNowPlaying() instead.
                ->orderBy('last_played_at')
                ->take($needed)
                ->get();

            if ($songs->isEmpty()) {
                return;
            }

            $maxPos = QueueItem::where('status', 'pending')->max('position') ?? 0;

            foreach ($songs as $song) {
                QueueItem::create([
                    'station_id' => $stationId,
                    'media_asset_id' => $song->id,
                    'requested_by_name' => null,
                    'position' => ++$maxPos,
                    'status' => 'pending',
                ]);
            }

            $this->bumpQueueVersion($stationId);
        });
    }

    private function onSongPlayed(int $stationId, ?int $queueItemId): void
    {
        Setting::inc(SettingKey::SongsSinceLastCommercial, 1, $stationId);
        Setting::inc(SettingKey::SongsSinceLastSoundByte, 1, $stationId);

        if ($queueItemId) {
            QueueItem::where('id', $queueItemId)->update(['status' => 'playing']);
            $this->compactPositions();
        }
    }

    private function onCommercialPlayed(int $stationId, ?int $commercialId): void
    {
        Setting::set(SettingKey::SongsSinceLastCommercial, 0, $stationId);
        Setting::set(SettingKey::ForceCommercialId, 0, $stationId);
        if ($commercialId) {
            Setting::set(SettingKey::LastCommercialId, $commercialId, $stationId);
            MediaAsset::where('id', $commercialId)->increment('play_count');
        }
    }

    private function onSoundBytePlayed(int $stationId): void
    {
        Setting::set(SettingKey::SongsSinceLastSoundByte, 0, $stationId);
        Setting::set(SettingKey::ForceSoundByteId, 0, $stationId);
    }

    public function addToQueue(int $stationId, int $songId, ?string $name): QueueItem
    {
        return CurrentStation::as($stationId, fn () => DB::transaction(function () use ($stationId, $songId, $name) {
            $this->lockStation($stationId);

            $maxPos = QueueItem::where('status', 'pending')->max('position') ?? 0;

            $item = QueueItem::create([
                'station_id' => $stationId,
                'media_asset_id' => $songId,
                'requested_by_name' => $name,
                'position' => $maxPos + 1,
                'status' => 'pending',
            ]);

            $this->bumpQueueVersion($stationId);

            return $item;
        }));
    }

    public function skipCurrent(int $stationId): void
    {
        CurrentStation::as($stationId, fn () => QueueItem::where('status', 'playing')->update([
            'status' => 'skipped',
            'played_at' => now(),
        ]));
    }

    public function playNow(int $stationId, int $songId, ?string $name = null): QueueItem
    {
        return CurrentStation::as($stationId, fn () => DB::transaction(function () use ($stationId, $songId, $name) {
            $this->lockStation($stationId);
            $this->skipCurrent($stationId);
            QueueItem::where('status', 'pending')->increment('position');

            return QueueItem::create([
                'station_id' => $stationId,
                'media_asset_id' => $songId,
                'requested_by_name' => $name ?? 'Admin',
                'position' => 1,
                'status' => 'pending',
            ]);
        }));
    }

    /**
     * Reconcile what a device says is on its SD card against what we think it holds.
     *
     * Three queries regardless of library size. It used to be two per reported file --
     * a lookup and a firstOrCreate -- so a Pi reporting a 500-song card spent a
     * thousand round trips inside one request.
     *
     * @param  array<int, array{filename: string, file_size?: int|null}>  $songs
     * @return array{added: int, unchanged: int}
     */
    public function syncLibrary(PiToken $token, array $songs): array
    {
        $filenames = collect($songs)
            ->pluck('filename')
            ->reject(fn (string $name) => $this->isRuntimePiFile($name))
            ->unique();

        $songIds = MediaAsset::query()->songs()
            ->whereIn('filename', $filenames)
            ->pluck('id');

        $known = DeviceDownload::where('pi_token_id', $token->id)
            ->where('media_type', 'song')
            ->whereIn('media_id', $songIds)
            ->pluck('media_id');

        $new = $songIds->diff($known);

        if ($new->isNotEmpty()) {
            DeviceDownload::insert($new->map(fn (int $id) => [
                'pi_token_id' => $token->id,
                'media_type' => 'song',
                'media_id' => $id,
                'downloaded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        }

        return ['added' => $new->count(), 'unchanged' => $known->count()];
    }

    private function isRuntimePiFile(string $filename): bool
    {
        if ($filename === '' || basename($filename) !== $filename) {
            return true;
        }

        return in_array(strtolower($filename), [
            'ftpa.wav',
            'station_id.wav',
        ], true);
    }

    /**
     * Close the gaps a played or removed item leaves in the pending positions.
     *
     * One UPDATE per distinct shift rather than one per row. This runs on every song
     * change, and it was the most frequent N+1 in the app; in the ordinary case -- the
     * head of the queue was just played, so everything behind it moves up one -- that
     * is a single statement, and rows already in the right place are not touched.
     */
    private function compactPositions(): void
    {
        $positions = QueueItem::pending()->pluck('position', 'id');

        $shifts = [];
        $target = 0;

        foreach ($positions as $id => $position) {
            $target++;

            if ($position !== $target) {
                $shifts[$position - $target][] = $id;
            }
        }

        foreach ($shifts as $by => $ids) {
            QueueItem::whereIn('id', $ids)->decrement('position', $by);
        }
    }

    public function bumpQueueVersion(int $stationId): void
    {
        Cache::put("sse.queue_version.{$stationId}", (string) microtime(true), 3600);
    }
}
