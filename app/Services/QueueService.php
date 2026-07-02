<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\NowPlaying;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * Read-only peek at the next $limit pending songs — no state changes.
     * Used by the Pi to pre-decode upcoming songs before they're needed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peekUpcoming(int $limit = 3): array
    {
        return QueueItem::with('song')
            ->pending()
            ->skip(1)           // skip 'next' (already returned by getNextForPi)
            ->take($limit)
            ->get()
            ->map(fn (QueueItem $item) => [
                'queue_item_id' => $item->id,
                'song' => [
                    'id'               => $item->song->id,
                    'title'            => $item->song->title,
                    'artist'           => $item->song->artist,
                    'filename'         => $item->song->filename,
                    'duration_seconds' => $item->song->duration_seconds,
                ],
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function getNextForPi(): array
    {
        $this->autoFillQueue();

        // ── Commercial scheduling ─────────────────────────────────────────
        $commercial = null;
        $forcedCommercialId = (int) Setting::get('force_commercial_id', 0);
        if ($forcedCommercialId) {
            $commercial = Commercial::active()->find($forcedCommercialId);
        }
        if (! $commercial) {
            $comInterval = (int) Setting::get('commercial_interval', 0);
            $songsSinceCom = (int) Setting::get('songs_since_last_commercial', 0);
            if ($comInterval > 0 && $songsSinceCom >= $comInterval) {
                $commercial = Commercial::nextInRotation();
            }
        }

        // ── Sound byte scheduling ─────────────────────────────────────────
        $soundByte = null;
        $forcedSoundByteId = (int) Setting::get('force_sound_byte_id', 0);
        if ($forcedSoundByteId) {
            $soundByte = SoundByte::active()->find($forcedSoundByteId);
        }
        if (! $soundByte) {
            $sbInterval = (int) Setting::get('sound_byte_interval', 0);
            $songsSinceSb = (int) Setting::get('songs_since_last_sound_byte', 0);
            if ($sbInterval > 0 && $songsSinceSb >= $sbInterval) {
                $soundByte = SoundByte::active()->inRandomOrder()->first();
            }
        }

        // ── Next queued song ──────────────────────────────────────────────
        $next = QueueItem::with('song')->pending()->first();

        return [
            'commercial' => $commercial ? [
                'id' => $commercial->id,
                'filename' => $commercial->filename,
                'title' => $commercial->title,
            ] : null,
            'sound_byte' => $soundByte ? [
                'id'       => $soundByte->id,
                'filename' => $soundByte->filename,
                'title'    => $soundByte->title,
                'category' => $soundByte->category,
                'rds_ps'   => $soundByte->rds_ps,
            ] : null,
            'next' => $next ? [
                'queue_item_id'     => $next->id,
                'requested_by_name' => $next->requested_by_name,
                'song' => [
                    'id'               => $next->song->id,
                    'title'            => $next->song->title,
                    'artist'           => $next->song->artist,
                    'filename'         => $next->song->filename,
                    'duration_seconds' => $next->song->duration_seconds,
                ],
            ] : null,
        ];
    }

    public function markNowPlaying(string $type, ?int $queueItemId, ?string $filename, ?int $itemId = null): void
    {
        DB::transaction(function () use ($type, $queueItemId, $filename, $itemId) {
            QueueItem::where('status', 'playing')->update([
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
                ['id' => 1],
                [
                    'song_id' => $songId,
                    'queue_item_id' => $queueItemId,
                    'type' => $type,
                    'started_at' => now(),
                ]
            );

            match ($type) {
                'commercial' => $this->onCommercialPlayed($itemId),
                'sound_byte' => $this->onSoundBytePlayed(),
                default => $this->onSongPlayed($queueItemId), // 'song'
            };

            // Push SSE events
            $npPayload = match ($type) {
                'commercial' => ['type' => 'commercial', 'song' => ['id' => null, 'title' => 'Commercial Break', 'artist' => null], 'queue_item_id' => null, 'started_at' => now()->toIso8601String()],
                'sound_byte' => ['type' => 'sound_byte',  'song' => ['id' => null, 'title' => 'Radio Drop',       'artist' => null], 'queue_item_id' => null, 'started_at' => now()->toIso8601String()],
                default       => ['type' => 'song', 'song' => $song ? ['id' => $song->id, 'title' => $song->title, 'artist' => $song->artist] : null, 'queue_item_id' => $queueItemId, 'started_at' => now()->toIso8601String()],
            };
            Cache::put('sse.now_playing', $npPayload, 3600);
            $this->bumpQueueVersion();
        });
    }

    private function autoFillQueue(int $target = 10): void
    {
        $pendingCount = QueueItem::where('status', 'pending')->count();
        $needed = $target - $pendingCount;

        if ($needed <= 0) {
            return;
        }

        // Exclude songs already pending or currently playing
        $excludeIds = QueueItem::whereIn('status', ['pending', 'playing'])->pluck('song_id');

        $songs = Song::available()
            ->whereNotIn('id', $excludeIds)
            ->orderByRaw('(SELECT MAX(played_at) FROM queue_items WHERE queue_items.song_id = songs.id AND queue_items.status = "played") ASC')
            ->take($needed)
            ->get();

        if ($songs->isEmpty()) {
            return;
        }

        $maxPos = QueueItem::where('status', 'pending')->max('position') ?? 0;

        foreach ($songs as $song) {
            QueueItem::create([
                'song_id'           => $song->id,
                'requested_by_name' => null,
                'position'          => ++$maxPos,
                'status'            => 'pending',
            ]);
        }

        $this->bumpQueueVersion();
    }

    private function onSongPlayed(?int $queueItemId): void
    {
        Setting::inc('songs_since_last_commercial');
        Setting::inc('songs_since_last_sound_byte');

        if ($queueItemId) {
            QueueItem::where('id', $queueItemId)->update(['status' => 'playing']);
            $this->compactPositions();
        }
    }

    private function onCommercialPlayed(?int $commercialId): void
    {
        Setting::set('songs_since_last_commercial', 0);
        Setting::set('force_commercial_id', 0);
        if ($commercialId) {
            Setting::set('last_commercial_id', $commercialId);
            Commercial::where('id', $commercialId)->increment('play_count');
        }
    }

    private function onSoundBytePlayed(): void
    {
        Setting::set('songs_since_last_sound_byte', 0);
        Setting::set('force_sound_byte_id', 0);
    }

    public function addToQueue(int $songId, ?string $name): QueueItem
    {
        $maxPos = QueueItem::where('status', 'pending')->max('position') ?? 0;

        $item = QueueItem::create([
            'song_id' => $songId,
            'requested_by_name' => $name,
            'position' => $maxPos + 1,
            'status' => 'pending',
        ]);

        $this->bumpQueueVersion();

        return $item;
    }

    public function skipCurrent(): void
    {
        QueueItem::where('status', 'playing')->update([
            'status' => 'skipped',
            'played_at' => now(),
        ]);
    }

    public function playNow(int $songId, ?string $name = null): QueueItem
    {
        return DB::transaction(function () use ($songId, $name) {
            $this->skipCurrent();
            QueueItem::where('status', 'pending')->increment('position');

            return QueueItem::create([
                'song_id' => $songId,
                'requested_by_name' => $name ?? 'Admin',
                'position' => 1,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * @param array<int, array{filename: string, file_size?: int|null}> $songs
     * @return array{added: int, unchanged: int, removed: int}
     */
    public function syncLibrary(array $songs): array
    {
        $filenames = collect($songs)->pluck('filename')->all();
        $added = 0;
        $unchanged = 0;

        foreach ($songs as $data) {
            $exists = Song::where('filename', $data['filename'])->first();
            if ($exists) {
                $exists->update(['available' => true, 'file_size' => $data['file_size'] ?? null]);
                $unchanged++;
            } else {
                Song::create([
                    'title' => pathinfo($data['filename'], PATHINFO_FILENAME),
                    'artist' => '',
                    'filename' => $data['filename'],
                    'file_size' => $data['file_size'] ?? null,
                    'available' => true,
                ]);
                $added++;
            }
        }

        $removed = Song::whereNotIn('filename', $filenames)->where('available', true)->count();
        Song::whereNotIn('filename', $filenames)->update(['available' => false]);

        return compact('added', 'unchanged', 'removed');
    }

    private function compactPositions(): void
    {
        $items = QueueItem::pending()->get();
        foreach ($items as $i => $item) {
            $item->update(['position' => $i + 1]);
        }
    }

    public function bumpQueueVersion(): void
    {
        Cache::put('sse.queue_version', (string) microtime(true), 3600);
    }
}
