<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MediaType;
use App\Enums\SettingKey;
use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Station;
use App\Services\QueueService;
use App\Support\PiPresence;
use App\Support\StationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    use HasActiveStation;

    public function __construct(private QueueService $queueService) {}

    /** The mode and RDS keys the page's two forms edit. */
    private const PAGE_KEYS = [
        SettingKey::BroadcastMode,
        SettingKey::LiveStreamUrl,
        SettingKey::LiveAlsaDevice,
        SettingKey::RdsRtMode,
        SettingKey::RdsRt,
        SettingKey::RdsPs,
    ];

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $settings = StationSettings::for($station->id)->formValues(self::PAGE_KEYS);

        $np = NowPlaying::forStation($station->id);

        return Inertia::render('admin/broadcast', [
            'songs' => MediaAsset::query()->songs()->active()->orderBy('title')->get(['id', 'title', 'artist', 'duration_seconds']),
            'commercials' => MediaAsset::query()->ofType(MediaType::Commercial)->active()->orderBy('rotation_order')->orderBy('title')->get(['id', 'title', 'play_count']),
            'soundBytes' => MediaAsset::query()->ofType(MediaType::SoundByte)->active()->orderBy('category')->orderBy('title')->get(['id', 'title', 'category']),
            'settings' => $settings,
            'pi' => $this->aggregatePiStatus($station),
            'nowPlaying' => $np ? [
                'title' => $np->mediaAsset !== null ? $np->mediaAsset->title : ucwords(str_replace('_', ' ', $np->type ?? '')),
                'artist' => $np->mediaAsset?->artist,
                'type' => $np->type,
            ] : null,
        ]);
    }

    public function setMode(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'broadcast_mode' => ['required', 'in:normal,phone_stream,usb_input,custom_stream'],
            'live_stream_url' => ['nullable', 'string', 'max:255'],
            'live_alsa_device' => ['nullable', 'string', 'max:50'],
        ]);

        Setting::set(SettingKey::BroadcastMode, $data['broadcast_mode'], $station->id);
        Setting::set(SettingKey::LiveStreamUrl, $data['live_stream_url'] ?? '', $station->id);
        Setting::set(SettingKey::LiveAlsaDevice, $data['live_alsa_device'] ?? 'hw:1,0', $station->id);

        return back()->with('success', 'Broadcast mode updated. Pi will switch within 30 seconds.');
    }

    public function updateRds(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'rds_rt_mode' => ['required', 'in:auto,custom'],
            'rds_rt' => ['nullable', 'string', 'max:64'],
            'rds_ps' => ['nullable', 'string', 'max:8'],
        ]);

        Setting::set(SettingKey::RdsRtMode, $data['rds_rt_mode'], $station->id);
        Setting::set(SettingKey::RdsRt, $data['rds_rt'] ?? '', $station->id);
        Setting::set(SettingKey::RdsPs, $data['rds_ps'] ?? '', $station->id);

        return back()->with('success', 'RDS settings saved.');
    }

    public function skip(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $this->queueService->skipCurrent($station->id);

        // Signal every device on this station to abort the current song on its next heartbeat.
        PiToken::where('station_id', $station->id)->update(['pi_skip_next' => true]);

        return back()->with('success', 'Current song skipped.');
    }

    public function playNow(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'song_id' => ['required', 'integer', $this->existsAs(MediaType::Song)],
        ]);

        $this->queueService->playNow($station->id, (int) $data['song_id']);

        return back()->with('success', 'Song injected at front of queue.');
    }

    public function forceCommercial(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'commercial_id' => ['required', 'integer', $this->existsAs(MediaType::Commercial)],
        ]);

        Setting::set(SettingKey::ForceCommercialId, $data['commercial_id'], $station->id);

        return back()->with('success', 'Commercial will play on next Pi poll (within 30 s).');
    }

    public function forceSoundByte(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'sound_byte_id' => ['required', 'integer', $this->existsAs(MediaType::SoundByte)],
        ]);

        Setting::set(SettingKey::ForceSoundByteId, $data['sound_byte_id'], $station->id);

        return back()->with('success', 'Sound byte will play on next Pi poll (within 30 s).');
    }

    public function emergency(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        QueueItem::where('station_id', $station->id)->pending()->update(['status' => 'skipped', 'played_at' => now()]);
        Setting::set(SettingKey::PiEmergency, '1', $station->id);
        PiToken::where('station_id', $station->id)->update(['pi_skip_next' => true]);
        $this->queueService->bumpQueueVersion($station->id);

        return back()->with('success', 'Emergency broadcast triggered — Pi switches within 30 s.');
    }

    /**
     * All three live in `media_assets` now, so a bare `exists:media_assets,id` would let
     * a commercial id be injected as a song. The type is part of the constraint.
     */
    private function existsAs(MediaType $type): Exists
    {
        return Rule::exists('media_assets', 'id')->where('type', $type->value);
    }

    /** @return array<string, mixed> */
    private function aggregatePiStatus(Station $station): array
    {
        $tokens = PiToken::where('station_id', $station->id)->get();
        $online = PiPresence::online($tokens);
        $status = PiPresence::status($online);

        $primary = $online->sortByDesc('last_seen_at')->first();

        return [
            'online' => $online->isNotEmpty(),
            'status' => $status,
            'mode' => $primary->pi_mode ?? 'normal',
            'ip' => $primary?->pi_ip,
            'last_seen' => $tokens->sortByDesc('last_seen_at')->first()?->last_seen_at?->diffForHumans(),
            'device_count' => $tokens->count(),
        ];
    }
}
