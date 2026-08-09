<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NowPlaying;
use App\Models\PiCommand;
use App\Models\PiToken;
use App\Models\Setting;
use App\Models\Station;
use App\Models\WifiNetwork;
use App\Services\DeviceSyncService;
use App\Services\QueueService;
use App\Support\PiSource;
use App\Support\PublicStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PiController extends Controller
{
    public function __construct(
        private QueueService $queueService,
        private DeviceSyncService $deviceSyncService,
    ) {}

    public function queue(Request $request): JsonResponse
    {
        $stationId = $this->stationIdFor($request);

        $data = $this->queueService->getNextForPi($stationId);

        $lookahead = min((int) $request->query('lookahead', 0), 5);
        if ($lookahead > 1 && isset($data['next'])) {
            $data['upcoming'] = $this->queueService->peekUpcoming($stationId, $lookahead);
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
            $this->stationIdFor($request),
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

        /** @var PiToken $token */
        $token = $request->attributes->get('pi_token');

        $counts = $this->queueService->syncLibrary($token, $data['songs']);

        return response()->json(['ok' => true, ...$counts]);
    }

    public function config(Request $request): JsonResponse
    {
        [$station, $token] = $this->resolveStationAndToken($request);

        return response()->json($this->buildConfig($station, $token));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:idle,playing,live'],
            'mode' => ['required', 'string', 'max:30'],
            'ip' => ['nullable', 'string', 'max:45'],
            'wifi_ssid' => ['nullable', 'string', 'max:100'],
            'wifi_networks' => ['nullable', 'array'],
            'wifi_applied' => ['nullable', 'string', 'max:100'],
            'wifi_failed' => ['nullable', 'string', 'max:100'],
            'daemon_hash' => ['nullable', 'string', 'max:16'],
            'disk_free_bytes' => ['nullable', 'integer'],
            'disk_total_bytes' => ['nullable', 'integer'],
            'wifi_profiles_rev' => ['nullable', 'string', 'max:32'],
            'last_error_kind' => ['nullable', 'string', 'max:20'],
            'last_error_message' => ['nullable', 'string', 'max:255'],
            'last_error_at' => ['nullable', 'string', 'max:32'],
            // Sent by the daemon since forever, silently dropped until now.
            'fm_running' => ['nullable', 'boolean'],
            'schedule_queue_depth' => ['nullable', 'integer'],
            'ready_queue_depth' => ['nullable', 'integer'],
            'last_update_result' => ['nullable', 'string', 'max:20'],
        ]);

        /** @var PiToken|null $token */
        $token = $request->attributes->get('pi_token');
        $stationId = $this->stationIdFor($request);

        // Station-suffixed: these keys were global, so the last Pi to heartbeat
        // on ANY station overwrote the wifi scan shown for every other station.
        $scope = ".{$stationId}";

        // Cache latest WiFi scan from Pi for the admin settings page
        if (isset($data['wifi_ssid'])) {
            Cache::put('pi.wifi_ssid'.$scope, $data['wifi_ssid'], 300);
        }
        if (isset($data['wifi_networks'])) {
            Cache::put('pi.wifi_networks'.$scope, $data['wifi_networks'], 300);
        }

        // WiFi switch result — clear pending and store outcome
        if ($data['wifi_applied'] ?? null) {
            Setting::set('pending_wifi_ssid', '', $stationId);
            Setting::set('pending_wifi_password', '', $stationId);
            Setting::set('last_wifi_status', 'connected:'.$data['wifi_applied'], $stationId);
        }
        if ($data['wifi_failed'] ?? null) {
            Setting::set('last_wifi_status', 'failed:'.$data['wifi_failed'], $stationId);
        }

        // What the Pi actually has on disk, so the settings page can flag drift
        // from the saved list (e.g. a Pi that's been offline since an edit).
        Cache::put('pi.wifi_profiles_rev'.$scope, $data['wifi_profiles_rev'] ?? '', 300);

        if ($data['last_error_message'] ?? null) {
            Cache::put('pi.last_error'.$scope, [
                'kind' => $data['last_error_kind'] ?? '',
                'message' => $data['last_error_message'],
                'at' => $data['last_error_at'] ?? '',
            ], 300);
        } else {
            Cache::forget('pi.last_error'.$scope);
        }

        $skipNext = false;
        if ($token) {
            $skipNext = $this->piTokenHasColumn('pi_skip_next') ? (bool) $token->pi_skip_next : false;

            $updates = [];

            if ($this->piTokenHasColumn('pi_status')) {
                $updates['pi_status'] = $data['status'];
            }
            if ($this->piTokenHasColumn('pi_mode')) {
                $updates['pi_mode'] = $data['mode'];
            }
            if ($this->piTokenHasColumn('pi_ip')) {
                $updates['pi_ip'] = $data['ip'] ?? $token->pi_ip;
            }

            if ($this->piTokenHasColumn('pi_skip_next')) {
                $updates['pi_skip_next'] = false;
            }
            if ($this->piTokenHasColumn('pi_daemon_hash')) {
                $updates['pi_daemon_hash'] = $data['daemon_hash'] ?? $token->pi_daemon_hash;
            }
            if ($this->piTokenHasColumn('disk_free_bytes')) {
                $updates['disk_free_bytes'] = $data['disk_free_bytes'] ?? $token->disk_free_bytes;
            }
            if ($this->piTokenHasColumn('disk_total_bytes')) {
                $updates['disk_total_bytes'] = $data['disk_total_bytes'] ?? $token->disk_total_bytes;
            }

            // Health signals. fm_running is the one that distinguishes "the
            // daemon is alive" from "the station is actually on the air".
            if ($this->piTokenHasColumn('pi_fm_running')) {
                $updates['pi_fm_running'] = $data['fm_running'] ?? $token->pi_fm_running;
            }
            if ($this->piTokenHasColumn('pi_queue_depth')) {
                $updates['pi_queue_depth'] = $data['ready_queue_depth'] ?? $token->pi_queue_depth;
            }
            if ($this->piTokenHasColumn('pi_last_error')) {
                $updates['pi_last_error'] = $data['last_error_message'] ?? null;
            }
            if ($this->piTokenHasColumn('pi_last_update_status') && isset($data['last_update_result'])) {
                $updates['pi_last_update_status'] = $data['last_update_result'];
                $updates['pi_last_update_at'] = now();
            }

            if ($updates !== []) {
                try {
                    $token->update($updates);
                } catch (\Throwable $e) {
                    // Telemetry fields (status/ip/disk stats) must never be able to break the
                    // heartbeat/config-sync channel — log and continue with whatever succeeded.
                    Log::warning('Pi heartbeat: failed to persist token updates', [
                        'pi_token_id' => $token->id,
                        'keys' => array_keys($updates),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Cache::put("sse.pi_status.{$stationId}", [
                'online' => true,
                'status' => $data['status'],
                'mode' => $data['mode'],
                'ip' => $data['ip'] ?? ($this->piTokenHasColumn('pi_ip') ? $token->pi_ip : null),
            ], 180);
        }

        $station = Station::find($stationId) ?? Station::findOrFail(Station::defaultId());
        $config = $this->buildConfig($station, $token);

        // Consume emergency / update flags after including them in this response
        if ($config['emergency'] ?? false) {
            Setting::set('pi_emergency', '0', $stationId);
        }
        if ($config['apply_update'] ?? false) {
            Setting::set('pi_update_requested', '0', $stationId);
        }

        return response()->json([
            ...$config,
            'skip_next' => $skipNext,
            'commands' => $token ? $this->dispatchCommands($token) : [],
        ]);
    }

    /**
     * Hand this device its queued commands and mark them sent.
     *
     * Scoped to $token, so unlike the station-wide flags it sits beside, one Pi
     * picking up work can never starve another on the same station.
     *
     * @return array<int, array{id: int, command: string, payload: string|null}>
     */
    private function dispatchCommands(PiToken $token): array
    {
        if (! Schema::hasTable('pi_commands')) {
            return [];
        }

        // A device that went down mid-command (reboot, failed update) never
        // acks; without this its row would pin the UI on "in progress" forever.
        PiCommand::expireStale();

        $queued = PiCommand::query()
            ->where('pi_token_id', $token->id)
            ->queued()
            ->orderBy('id')
            ->get();

        return $queued->map(function (PiCommand $cmd): array {
            $cmd->markSent();

            return [
                'id' => $cmd->id,
                'command' => $cmd->command,
                'payload' => $cmd->payload,
            ];
        })->all();
    }

    /** Device reports the outcome of a command it was handed. */
    public function ackCommand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'ok' => ['required', 'boolean'],
            'result' => ['nullable', 'string', 'max:20000'],
        ]);

        $token = $request->attributes->get('pi_token');

        if (! $token || ! Schema::hasTable('pi_commands')) {
            return response()->json(['ok' => false], 404);
        }

        $command = PiCommand::query()
            ->where('pi_token_id', $token->id)   // a device may only ack its own
            ->find($data['id']);

        if (! $command) {
            return response()->json(['ok' => false], 404);
        }

        $data['ok']
            ? $command->markAcked($data['result'] ?? null)
            : $command->markFailed($data['result'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function confirmDownload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:song,commercial,sound_byte'],
            'item_id' => ['nullable', 'integer'],
            'song_id' => ['nullable', 'integer'], // legacy field
        ]);

        $token = $request->attributes->get('pi_token');
        $type = $data['type'] ?? 'song';
        $itemId = $data['item_id'] ?? $data['song_id'] ?? null;

        if ($itemId && $token) {
            $this->deviceSyncService->recordDownload($token, $type, $itemId);
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

        $token = $request->attributes->get('pi_token');
        $type = $data['type'] ?? 'song';
        $itemId = $data['item_id'] ?? $data['song_id'] ?? null;

        if ($itemId && $token) {
            $this->deviceSyncService->recordDelete($token, $type, $itemId);
        }

        return response()->json(['ok' => true]);
    }

    public function piStatus(Request $request): JsonResponse
    {
        $station = $this->resolvePublicStation($request);
        $tokens = $this->piTokenHasColumn('station_id')
            ? PiToken::where('station_id', $station->id)->get()
            : PiToken::query()->get();
        $online = $tokens->filter(fn (PiToken $t) => $t->last_seen_at && $t->last_seen_at->diffInSeconds(now()) < 120);

        if ($online->isEmpty()) {
            return response()->json([
                'online' => false,
                'status' => 'offline',
                'mode' => 'normal',
                'ip' => null,
                'update_available' => false,
            ]);
        }

        $status = 'idle';
        foreach (['live', 'playing'] as $candidate) {
            if ($this->piTokenHasColumn('pi_status') && $online->contains(fn (PiToken $t) => $t->pi_status === $candidate)) {
                $status = $candidate;
                break;
            }
        }

        $primary = $online->sortByDesc('last_seen_at')->first();
        $currentSourceHash = self::currentPiSourceHash();

        return response()->json([
            'online' => true,
            'status' => $status,
            'mode' => $this->piTokenHasColumn('pi_mode') ? ($primary->pi_mode ?? 'normal') : 'normal',
            'ip' => $this->piTokenHasColumn('pi_ip') ? $primary->pi_ip : null,
            // null hash means the running Pi install predates hash-reporting; don't nag until it's known.
            'update_available' => $this->piTokenHasColumn('pi_daemon_hash')
                && $online->contains(fn (PiToken $t) => $t->pi_daemon_hash !== null && $t->pi_daemon_hash !== $currentSourceHash),
        ]);
    }

    public function nowPlayingPublic(Request $request): JsonResponse
    {
        $station = $this->resolvePublicStation($request);
        $np = NowPlaying::forStation($station->id);

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

    /** Resolve the station id for the authenticated device, falling back to the default station for an unassigned token. */
    private function stationIdFor(Request $request): int
    {
        /** @var PiToken|null $token */
        $token = $request->attributes->get('pi_token');

        if ($token && $this->piTokenHasColumn('station_id') && $token->station_id) {
            return $token->station_id;
        }

        if ($token) {
            Log::warning('Pi device has no assigned station — falling back to default', ['pi_token_id' => $token->id]);
        }

        return Station::defaultId();
    }

    /** @return array{0: Station, 1: PiToken} */
    private function resolveStationAndToken(Request $request): array
    {
        /** @var PiToken $token */
        $token = $request->attributes->get('pi_token');
        $stationId = $this->stationIdFor($request);
        $station = Station::find($stationId) ?? Station::findOrFail(Station::defaultId());

        return [$station, $token];
    }

    /** Public (unauthenticated) endpoints resolve by an optional ?station=slug query param, defaulting to the default station. */
    private function resolvePublicStation(Request $request): Station
    {
        return PublicStation::resolve($request);
    }

    /** @return array<string, mixed> */
    private function piTokenHasColumn(string $column): bool
    {
        return Schema::hasColumn((new PiToken)->getTable(), $column);
    }

    private function buildConfig(Station $station, PiToken $token): array
    {
        $pendingWifiSsid = Setting::get('pending_wifi_ssid', '', $station->id);

        return [
            'freq' => (float) Setting::get('frequency', '96.9', $station->id),
            'broadcast_mode' => Setting::get('broadcast_mode', 'normal', $station->id),
            'live_stream_url' => Setting::get('live_stream_url', '', $station->id),
            'live_alsa_device' => Setting::get('live_alsa_device', 'hw:1,0', $station->id),
            'rds_rt_mode' => Setting::get('rds_rt_mode', 'auto', $station->id),
            'rds_rt' => Setting::get('rds_rt', '', $station->id),
            'rds_ps' => Setting::get('rds_ps', '', $station->id),
            'callsign' => Setting::get('callsign', '96.9 FM', $station->id),
            'fallback_song' => Setting::get('fallback_song', 'FTPA.wav', $station->id),
            'fade_in_duration' => (float) Setting::get('fade_in_duration', 0.5, $station->id),
            'pending_downloads' => $this->deviceSyncService->pendingDownloadsFor($token),
            'pending_deletes' => $this->deviceSyncService->pendingDeletesFor($token),
            'pending_wifi' => $pendingWifiSsid ? [
                'ssid' => $pendingWifiSsid,
                'password' => Setting::get('pending_wifi_password', '', $station->id),
            ] : null,
            // Full saved list, best-first. The Pi caches this to disk and hands it
            // to NetworkManager, so it can rejoin a fallback network at boot with
            // no server contact at all.
            'wifi_profiles' => WifiNetwork::profilesFor($station->id),
            'wifi_profiles_rev' => WifiNetwork::revisionFor($station->id),
            'emergency' => (bool) Setting::get('pi_emergency', '0', $station->id),
            'emergency_file' => Setting::get('emergency_announcement', 'announcement.wav', $station->id),
            'apply_update' => (bool) Setting::get('pi_update_requested', '0', $station->id),
        ];
    }

    /**
     * Short hash of the Pi source payload currently on the server, compared
     * against what each Pi reports. Delegates to PiSource so the hash, the
     * manifest, and the files actually served can never disagree.
     */
    private static function currentPiSourceHash(): string
    {
        return PiSource::hash();
    }
}
