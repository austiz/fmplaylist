<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\DeviceDownload;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Models\Station;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        return QueueItem::with('song')
            ->where('station_id', $stationId)
            ->pending()
            ->skip(1)           // skip 'next' (already returned by getNextForPi)
            ->take($limit)
            ->get()
            ->map(fn (QueueItem $item) => [
                'queue_item_id' => $item->id,
                'song' => [
                    'id' => $item->song->id,
                    'title' => $item->song->title,
                    'artist' => $item->song->artist,
                    'filename' => $item->song->filename,
                    'duration_seconds' => $item->song->duration_seconds,
                ],
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function getNextForPi(int $stationId): array
    {
        $this->autoFillQueue($stationId);

        // ── Commercial scheduling ─────────────────────────────────────────
        $commercial = null;
        $forcedCommercialId = (int) Setting::get('force_commercial_id', 0, $stationId);
        if ($forcedCommercialId) {
            $commercial = Commercial::active()->find($forcedCommercialId);
        }
        if (! $commercial) {
            $comInterval = (int) Setting::get('commercial_interval', 0, $stationId);
            $songsSinceCom = (int) Setting::get('songs_since_last_commercial', 0, $stationId);
            if ($comInterval > 0 && $songsSinceCom >= $comInterval) {
                $commercial = Commercial::nextInRotation($stationId);
            }
        }

        // ── Sound byte scheduling ─────────────────────────────────────────
        $soundByte = null;
        $forcedSoundByteId = (int) Setting::get('force_sound_byte_id', 0, $stationId);
        if ($forcedSoundByteId) {
            $soundByte = SoundByte::active()->find($forcedSoundByteId);
        }
        if (! $soundByte) {
            $sbInterval = (int) Setting::get('sound_byte_interval', 0, $stationId);
            $songsSinceSb = (int) Setting::get('songs_since_last_sound_byte', 0, $stationId);
            if ($sbInterval > 0 && $songsSinceSb >= $sbInterval) {
                $soundByte = SoundByte::active()->inRandomOrder()->first();
            }
        }

        // ── Next queued song ──────────────────────────────────────────────
        $next = QueueItem::with('song')->where('station_id', $stationId)->pending()->first();

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
                'song' => [
                    'id' => $next->song->id,
                    'title' => $next->song->title,
                    'artist' => $next->song->artist,
                    'filename' => $next->song->filename,
                    'duration_seconds' => $next->song->duration_seconds,
                ],
            ] : null,
        ];
    }

    public function markNowPlaying(int $stationId, string $type, ?int $queueItemId, ?string $filename, ?int $itemId = null): void
    {
        DB::transaction(function () use ($stationId, $type, $queueItemId, $filename, $itemId) {
            QueueItem::where('station_id', $stationId)->where('status', 'playing')->update([
                'status' => 'played',
                'played_at' => now(),
            ]);

            $song = null;
            $songId = null;
            if ($filename && $type === 'song') {
                $song = Song::where('filename', $filename)->first();
                $songId = $song?->id;
            }

            NowPlaying::updateOrCreate(
                ['station_id' => $stationId],
                [
                    'song_id' => $songId,
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

            // Push SSE events
            $npPayload = match ($type) {
                'commercial' => ['type' => 'commercial', 'song' => ['id' => null, 'title' => 'Commercial Break', 'artist' => null], 'queue_item_id' => null, 'started_at' => now()->toIso8601String()],
                'sound_byte' => ['type' => 'sound_byte',  'song' => ['id' => null, 'title' => 'Radio Drop',       'artist' => null], 'queue_item_id' => null, 'started_at' => now()->toIso8601String()],
                default => ['type' => 'song', 'song' => $song ? ['id' => $song->id, 'title' => $song->title, 'artist' => $song->artist, 'duration_seconds' => $song->duration_seconds] : null, 'queue_item_id' => $queueItemId, 'started_at' => now()->toIso8601String()],
            };
            Cache::put("sse.now_playing.{$stationId}", $npPayload, 3600);
            $this->bumpQueueVersion($stationId);
        });
    }

    private function autoFillQueue(int $stationId, ?int $target = null): void
    {
        $target ??= (int) config('fm.autofill_target');

        DB::transaction(function () use ($stationId, $target) {
            $this->lockStation($stationId);

            $pendingCount = QueueItem::where('station_id', $stationId)->where('status', 'pending')->count();
            $needed = $target - $pendingCount;

            if ($needed <= 0) {
                return;
            }

            // Exclude songs already pending or currently playing on THIS station
            // (the song library is shared across stations, so other stations' queues don't matter here)
            $excludeIds = QueueItem::where('station_id', $stationId)
                ->whereIn('status', ['pending', 'playing'])
                ->pluck('song_id');

            $songs = Song::available()
                ->whereNotIn('id', $excludeIds)
                ->orderByRaw('(SELECT MAX(played_at) FROM queue_items WHERE queue_items.song_id = songs.id AND queue_items.status = "played") ASC')
                ->take($needed)
                ->get();

            if ($songs->isEmpty()) {
                return;
            }

            $maxPos = QueueItem::where('station_id', $stationId)->where('status', 'pending')->max('position') ?? 0;

            foreach ($songs as $song) {
                QueueItem::create([
                    'station_id' => $stationId,
                    'song_id' => $song->id,
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
        Setting::inc('songs_since_last_commercial', 1, $stationId);
        Setting::inc('songs_since_last_sound_byte', 1, $stationId);

        if ($queueItemId) {
            QueueItem::where('id', $queueItemId)->update(['status' => 'playing']);
            $this->compactPositions($stationId);
        }
    }

    private function onCommercialPlayed(int $stationId, ?int $commercialId): void
    {
        Setting::set('songs_since_last_commercial', 0, $stationId);
        Setting::set('force_commercial_id', 0, $stationId);
        if ($commercialId) {
            Setting::set('last_commercial_id', $commercialId, $stationId);
            Commercial::where('id', $commercialId)->increment('play_count');
        }
    }

    private function onSoundBytePlayed(int $stationId): void
    {
        Setting::set('songs_since_last_sound_byte', 0, $stationId);
        Setting::set('force_sound_byte_id', 0, $stationId);
    }

    public function addToQueue(int $stationId, int $songId, ?string $name): QueueItem
    {
        return DB::transaction(function () use ($stationId, $songId, $name) {
            $this->lockStation($stationId);

            $maxPos = QueueItem::where('station_id', $stationId)->where('status', 'pending')->max('position') ?? 0;

            $item = QueueItem::create([
                'station_id' => $stationId,
                'song_id' => $songId,
                'requested_by_name' => $name,
                'position' => $maxPos + 1,
                'status' => 'pending',
            ]);

            $this->bumpQueueVersion($stationId);

            return $item;
        });
    }

    public function skipCurrent(int $stationId): void
    {
        QueueItem::where('station_id', $stationId)->where('status', 'playing')->update([
            'status' => 'skipped',
            'played_at' => now(),
        ]);
    }

    public function playNow(int $stationId, int $songId, ?string $name = null): QueueItem
    {
        return DB::transaction(function () use ($stationId, $songId, $name) {
            $this->lockStation($stationId);
            $this->skipCurrent($stationId);
            QueueItem::where('station_id', $stationId)->where('status', 'pending')->increment('position');

            return QueueItem::create([
                'station_id' => $stationId,
                'song_id' => $songId,
                'requested_by_name' => $name ?? 'Admin',
                'position' => 1,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * @param  array<int, array{filename: string, file_size?: int|null}>  $songs
     * @return array{added: int, unchanged: int}
     */
    public function syncLibrary(PiToken $token, array $songs): array
    {
        $added = 0;
        $unchanged = 0;

        foreach ($songs as $data) {
            $filename = $data['filename'];
            if ($this->isRuntimePiFile($filename)) {
                continue;
            }

            $song = Song::where('filename', $filename)->first();
            if (! $song) {
                continue;
            }

            $download = DeviceDownload::firstOrCreate(
                ['pi_token_id' => $token->id, 'media_type' => 'song', 'media_id' => $song->id],
                ['downloaded_at' => now()]
            );

            if ($download->wasRecentlyCreated) {
                $added++;
            } else {
                $unchanged++;
            }
        }

        return compact('added', 'unchanged');
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

    private function compactPositions(int $stationId): void
    {
        $items = QueueItem::where('station_id', $stationId)->pending()->get();
        foreach ($items as $i => $item) {
            $item->update(['position' => $i + 1]);
        }
    }

    public function bumpQueueVersion(int $stationId): void
    {
        Cache::put("sse.queue_version.{$stationId}", (string) microtime(true), 3600);
    }
}
