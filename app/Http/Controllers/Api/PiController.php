<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PiController extends Controller
{
    public function __construct(private QueueService $queueService) {}

    public function queue(Request $request): JsonResponse
    {
        $data = $this->queueService->getNextForPi();

        $lookahead = min((int) $request->query('lookahead', 0), 5);
        if ($lookahead > 1 && isset($data['next'])) {
            $data['upcoming'] = $this->queueService->peekUpcoming($lookahead);
        }

        return response()->json($data);
    }

    public function nowPlaying(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:song,station_id,commercial,sound_byte'],
            'queue_item_id' => ['nullable', 'integer'],
            'song_filename' => ['nullable', 'string'],
            'item_id' => ['nullable', 'integer'],
        ]);

        $this->queueService->markNowPlaying(
            $data['type'],
            $data['queue_item_id'] ?? null,
            $data['song_filename'] ?? null,
            $data['item_id'] ?? null,
        );

        return response()->json(['ok' => true, 'timestamp' => now()->toIso8601String()]);
    }

    public function syncLibrary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'songs' => ['present', 'array'],
            'songs.*.filename' => ['required', 'string'],
            'songs.*.file_size' => ['nullable', 'integer'],
        ]);

        $counts = $this->queueService->syncLibrary($data['songs']);

        return response()->json(['ok' => true, ...$counts]);
    }

    public function config(): JsonResponse
    {
        return response()->json(self::buildConfig());
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:idle,playing,live'],
            'mode' => ['required', 'string', 'max:30'],
            'ip' => ['nullable', 'string', 'max:45'],
        ]);

        /** @var PiToken|null $token */
        $token = $request->attributes->get('pi_token');
        $skipNext = false;
        if ($token) {
            $skipNext = (bool) $token->pi_skip_next;
            $token->update([
                'pi_status'    => $data['status'],
                'pi_mode'      => $data['mode'],
                'pi_ip'        => $data['ip'] ?? $token->pi_ip,
                'pi_skip_next' => false,  // consume the flag
            ]);

            Cache::put('sse.pi_status', [
                'online' => true,
                'status' => $data['status'],
                'mode'   => $data['mode'],
                'ip'     => $token->pi_ip,
            ], 180);
        }

        return response()->json([...self::buildConfig(), 'skip_next' => $skipNext]);
    }

    public function confirmDownload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:song,commercial,sound_byte'],
            'item_id' => ['nullable', 'integer'],
            'song_id' => ['nullable', 'integer'], // legacy field
        ]);

        $type = $data['type'] ?? 'song';
        $itemId = $data['item_id'] ?? $data['song_id'] ?? null;

        if ($itemId) {
            match ($type) {
                'commercial' => Commercial::where('id', $itemId)->update(['needs_pi_download' => false]),
                'sound_byte' => SoundByte::where('id', $itemId)->update(['needs_pi_download' => false]),
                default => Song::where('id', $itemId)->update(['needs_pi_download' => false]),
            };
        }

        return response()->json(['ok' => true]);
    }

    public function confirmDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:song,commercial,sound_byte'],
            'item_id' => ['nullable', 'integer'],
            'song_id' => ['nullable', 'integer'], // legacy field
        ]);

        $type = $data['type'] ?? 'song';
        $itemId = $data['item_id'] ?? $data['song_id'] ?? null;

        if ($itemId) {
            match ($type) {
                'commercial' => Commercial::where('id', $itemId)->delete(),
                'sound_byte' => SoundByte::where('id', $itemId)->delete(),
                default => Song::where('id', $itemId)->delete(),
            };
        }

        return response()->json(['ok' => true]);
    }

    public function piStatus(): JsonResponse
    {
        $token = PiToken::latest('last_seen_at')->first();

        if (! $token || ! $token->last_seen_at || $token->last_seen_at->diffInSeconds(now()) > 120) {
            return response()->json([
                'online' => false,
                'status' => 'offline',
                'mode' => 'normal',
                'ip' => null,
            ]);
        }

        return response()->json([
            'online' => true,
            'status' => $token->pi_status ?? 'idle',
            'mode' => $token->pi_mode ?? 'normal',
            'ip' => $token->pi_ip,
        ]);
    }

    public function nowPlayingPublic(): JsonResponse
    {
        $np = NowPlaying::with('song')->find(1);

        if (! $np) {
            return response()->json(null);
        }

        $display = match ($np->type) {
            'commercial' => ['title' => 'Commercial Break', 'artist' => null],
            'sound_byte' => ['title' => 'Radio Drop',       'artist' => null],
            'station_id' => ['title' => 'Station ID',       'artist' => null],
            default => $np->song
                ? ['title' => $np->song->title, 'artist' => $np->song->artist]
                : null,
        };

        if (! $display) {
            return response()->json(null);
        }

        return response()->json([
            'type' => $np->type,
            'song' => [
                'id' => $np->song?->id,
                'title' => $display['title'],
                'artist' => $display['artist'],
            ],
            'queue_item_id' => $np->queue_item_id,
            'started_at' => $np->started_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function buildConfig(): array
    {
        // Merge all pending downloads: songs + commercials + sound_bytes
        $pendingDownloads = collect();

        $pendingDownloads = $pendingDownloads->merge(
            Song::where('needs_pi_download', true)->where('available', true)
                ->get(['id', 'filename', 'title', 'artist', 'storage_path'])
                ->map(fn ($s) => [
                    'type' => 'song',
                    'item_id' => $s->id,
                    'filename' => $s->filename,
                    'title' => $s->title,
                    'download_url' => url(Storage::disk('public')->url($s->storage_path)),
                ])
        );

        $pendingDownloads = $pendingDownloads->merge(
            Commercial::where('needs_pi_download', true)->where('active', true)
                ->get(['id', 'filename', 'title', 'storage_path'])
                ->map(fn ($c) => [
                    'type' => 'commercial',
                    'item_id' => $c->id,
                    'filename' => $c->filename,
                    'title' => $c->title,
                    'download_url' => url(Storage::disk('public')->url($c->storage_path)),
                ])
        );

        $pendingDownloads = $pendingDownloads->merge(
            SoundByte::where('needs_pi_download', true)->where('active', true)
                ->get(['id', 'filename', 'title', 'storage_path'])
                ->map(fn ($sb) => [
                    'type' => 'sound_byte',
                    'item_id' => $sb->id,
                    'filename' => $sb->filename,
                    'title' => $sb->title,
                    'download_url' => url(Storage::disk('public')->url($sb->storage_path)),
                ])
        );

        $pendingDeletes = collect();

        $pendingDeletes = $pendingDeletes->merge(
            Song::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->map(fn ($s) => ['type' => 'song',       'item_id' => $s->id, 'filename' => $s->filename])
        );
        $pendingDeletes = $pendingDeletes->merge(
            Commercial::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->map(fn ($c) => ['type' => 'commercial', 'item_id' => $c->id, 'filename' => $c->filename])
        );
        $pendingDeletes = $pendingDeletes->merge(
            SoundByte::where('pi_delete_requested', true)->get(['id', 'filename'])
                ->map(fn ($sb) => ['type' => 'sound_byte', 'item_id' => $sb->id, 'filename' => $sb->filename])
        );

        return [
            'freq' => (float) Setting::get('frequency', '96.9'),
            'broadcast_mode' => Setting::get('broadcast_mode', 'normal'),
            'live_stream_url' => Setting::get('live_stream_url', ''),
            'live_alsa_device' => Setting::get('live_alsa_device', 'hw:1,0'),
            'rds_rt_mode' => Setting::get('rds_rt_mode', 'auto'),
            'rds_rt' => Setting::get('rds_rt', ''),
            'rds_ps' => Setting::get('rds_ps', ''),
            'callsign' => Setting::get('callsign', '96.9 FM'),
            'fallback_song' => Setting::get('fallback_song', 'FTPA.wav'),
            'fade_in_duration' => (float) Setting::get('fade_in_duration', 0.5),
            'pending_downloads' => $pendingDownloads->values(),
            'pending_deletes' => $pendingDeletes->values(),
        ];
    }
}
