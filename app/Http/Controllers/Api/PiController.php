<?php

namespace App\Http\Controllers\Api;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AckCommandRequest;
use App\Http\Requests\Api\ConfirmTransferRequest;
use App\Http\Requests\Api\HeartbeatRequest;
use App\Http\Requests\Api\NowPlayingReportRequest;
use App\Http\Requests\Api\SyncLibraryRequest;
use App\Http\Resources\NowPlayingResource;
use App\Models\NowPlaying;
use App\Models\PiCommand;
use App\Models\PiToken;
use App\Models\Setting;
use App\Models\Station;
use App\Models\WifiNetwork;
use App\Services\DeviceSyncService;
use App\Services\QueueService;
use App\Support\LiveState;
use App\Support\PiPresence;
use App\Support\PiSource;
use App\Support\PublicStation;
use App\Support\StationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    public function nowPlaying(NowPlayingReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->queueService->markNowPlaying(
            $this->stationIdFor($request),
            $data['type'],
            $data['queue_item_id'] ?? null,
            $data['song_filename'] ?? null,
            $data['item_id'] ?? null,
        );

        return response()->json(['ok' => true, 'timestamp' => now()->toIso8601String()]);
    }

    public function syncLibrary(SyncLibraryRequest $request): JsonResponse
    {
        $data = $request->validated();

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

    public function heartbeat(HeartbeatRequest $request): JsonResponse
    {
        $data = $request->validated();

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
            Setting::set(SettingKey::PendingWifiSsid, '', $stationId);
            Setting::set(SettingKey::PendingWifiPassword, '', $stationId);
            Setting::set(SettingKey::LastWifiStatus, 'connected:'.$data['wifi_applied'], $stationId);
        }
        if ($data['wifi_failed'] ?? null) {
            Setting::set(SettingKey::LastWifiStatus, 'failed:'.$data['wifi_failed'], $stationId);
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
            $skipNext = (bool) $token->pi_skip_next;

            $updates = [
                'pi_status' => $data['status'],
                'pi_mode' => $data['mode'],
                'pi_ip' => $data['ip'] ?? $token->pi_ip,
                'pi_skip_next' => false,
                'pi_daemon_hash' => $data['daemon_hash'] ?? $token->pi_daemon_hash,
                'disk_free_bytes' => $data['disk_free_bytes'] ?? $token->disk_free_bytes,
                'disk_total_bytes' => $data['disk_total_bytes'] ?? $token->disk_total_bytes,
                // Health signals. fm_running is the one that distinguishes "the
                // daemon is alive" from "the station is actually on the air".
                'pi_fm_running' => $data['fm_running'] ?? $token->pi_fm_running,
                'pi_queue_depth' => $data['ready_queue_depth'] ?? $token->pi_queue_depth,
                'pi_last_error' => $data['last_error_message'] ?? null,
            ];

            if (isset($data['last_update_result'])) {
                $updates['pi_last_update_status'] = $data['last_update_result'];
                $updates['pi_last_update_at'] = now();
            }

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

            // Built the same way the REST endpoint builds it, from the same helper:
            // the admin bar reads this out of the live frame now, and a frame that
            // disagreed with /api/pi-status would be a bug nobody would look for.
            LiveState::piStatusChanged($stationId, $this->piStatusPayload($stationId));
        }

        $station = Station::find($stationId) ?? Station::findOrFail(Station::defaultId());
        $config = $this->buildConfig($station, $token);

        return response()->json([
            ...$config,
            'skip_next' => $skipNext,
            'commands' => $token ? $this->dispatchCommands($token) : [],
        ]);
    }

    /**
     * Hand this device its queued commands and mark them sent.
     *
     * Scoped to $token, so one Pi picking up work can never starve another on the
     * same station -- which is exactly what the station-wide `emergency` and
     * `apply_update` config flags used to do before they moved onto this queue.
     *
     * @return array<int, array{id: int, command: string, payload: string|null}>
     */
    private function dispatchCommands(PiToken $token): array
    {
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
    public function ackCommand(AckCommandRequest $request): JsonResponse
    {
        $data = $request->validated();

        $token = $request->attributes->get('pi_token');

        if (! $token) {
            return response()->json(['ok' => false], 404);
        }

        $command = PiCommand::query()
            ->where('pi_token_id', $token->id)   // a device may only ack its own
            ->find((int) $data['id']);

        if (! $command) {
            return response()->json(['ok' => false], 404);
        }

        $data['ok']
            ? $command->markAcked($data['result'] ?? null)
            : $command->markFailed($data['result'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function confirmDownload(ConfirmTransferRequest $request): JsonResponse
    {
        $token = $request->attributes->get('pi_token');
        $itemId = $request->itemId();

        if ($itemId && $token) {
            $this->deviceSyncService->recordDownload($token, $request->mediaType(), $itemId);
        }

        return response()->json(['ok' => true]);
    }

    public function confirmDelete(ConfirmTransferRequest $request): JsonResponse
    {
        $token = $request->attributes->get('pi_token');
        $itemId = $request->itemId();

        if ($itemId && $token) {
            $this->deviceSyncService->recordDelete($token, $request->mediaType(), $itemId);
        }

        return response()->json(['ok' => true]);
    }

    public function piStatus(Request $request): JsonResponse
    {
        return response()->json($this->piStatusPayload($this->resolvePublicStation($request)->id));
    }

    /**
     * How a station's transmitters look from outside, collapsed to one line of status.
     *
     * @return array{online: bool, status: string, mode: string, ip: string|null, update_available: bool}
     */
    private function piStatusPayload(int $stationId): array
    {
        $online = PiPresence::online(PiToken::where('station_id', $stationId)->get());

        if ($online->isEmpty()) {
            return [
                'online' => false,
                'status' => 'offline',
                'mode' => 'normal',
                'ip' => null,
                'update_available' => false,
            ];
        }

        // Several Pis can share a station; the one heard from most recently speaks for it.
        $primary = $online->sortByDesc('last_seen_at')->firstOrFail();
        $currentSourceHash = self::currentPiSourceHash();

        return [
            'online' => true,
            'status' => PiPresence::status($online),
            'mode' => $primary->pi_mode ?? 'normal',
            'ip' => $primary->pi_ip,
            // null hash means the running Pi install predates hash-reporting; don't nag until it's known.
            'update_available' => $online->contains(
                fn (PiToken $t) => $t->pi_daemon_hash !== null && $t->pi_daemon_hash !== $currentSourceHash
            ),
        ];
    }

    public function nowPlayingPublic(Request $request): JsonResponse
    {
        $station = $this->resolvePublicStation($request);
        $np = NowPlaying::forStation($station->id);

        if (! $np) {
            return response()->json(null);
        }

        return response()->json((new NowPlayingResource($np))->resolve());
    }

    /** Resolve the station id for the authenticated device, falling back to the default station for an unassigned token. */
    private function stationIdFor(Request $request): int
    {
        /** @var PiToken|null $token */
        $token = $request->attributes->get('pi_token');

        if ($token && $token->station_id) {
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
    private function buildConfig(Station $station, PiToken $token): array
    {
        // One query for the whole bag: this used to be 16 separate SELECTs, on
        // the path every device hits every 30 seconds.
        $settings = StationSettings::for($station->id);
        $pendingWifiSsid = $settings->string(SettingKey::PendingWifiSsid);

        return [
            'freq' => $settings->float(SettingKey::Frequency),
            'broadcast_mode' => $settings->string(SettingKey::BroadcastMode),
            'live_stream_url' => $settings->string(SettingKey::LiveStreamUrl),
            'live_alsa_device' => $settings->string(SettingKey::LiveAlsaDevice),
            'rds_rt_mode' => $settings->string(SettingKey::RdsRtMode),
            'rds_rt' => $settings->string(SettingKey::RdsRt),
            'rds_ps' => $settings->string(SettingKey::RdsPs),
            'callsign' => $settings->string(SettingKey::Callsign),
            'fallback_song' => $settings->string(SettingKey::FallbackSong),
            'fade_in_duration' => $settings->float(SettingKey::FadeInDuration),
            'pending_downloads' => $this->deviceSyncService->pendingDownloadsFor($token),
            'pending_deletes' => $this->deviceSyncService->pendingDeletesFor($token),
            'pending_wifi' => $pendingWifiSsid !== '' ? [
                'ssid' => $pendingWifiSsid,
                'password' => $settings->string(SettingKey::PendingWifiPassword),
            ] : null,
            // Full saved list, best-first. The Pi caches this to disk and hands it
            // to NetworkManager, so it can rejoin a fallback network at boot with
            // no server contact at all.
            'wifi_profiles' => WifiNetwork::profilesFor($station->id),
            'wifi_profiles_rev' => WifiNetwork::revisionFor($station->id),
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
