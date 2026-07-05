<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Models\Station;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    use HasActiveStation;

    public function __construct(private QueueService $queueService) {}

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $settingKeys = [
            'broadcast_mode', 'live_stream_url', 'live_alsa_device',
            'rds_rt_mode', 'rds_rt', 'rds_ps',
        ];
        $settings = Setting::whereIn('key', $settingKeys)->where('station_id', $station->id)->pluck('value', 'key');

        $np = NowPlaying::forStation($station->id);

        return Inertia::render('admin/broadcast', [
            'songs' => Song::available()->orderBy('title')->get(['id', 'title', 'artist', 'duration_seconds']),
            'commercials' => Commercial::active()->orderBy('rotation_order')->orderBy('title')->get(['id', 'title', 'play_count']),
            'soundBytes' => SoundByte::active()->orderBy('category')->orderBy('title')->get(['id', 'title', 'category']),
            'settings' => $settings,
            'pi' => $this->aggregatePiStatus($station),
            'nowPlaying' => $np ? [
                'title' => $np->song !== null ? $np->song->title : ucwords(str_replace('_', ' ', $np->type ?? '')),
                'artist' => $np->song?->artist,
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

        Setting::set('broadcast_mode', $data['broadcast_mode'], $station->id);
        Setting::set('live_stream_url', $data['live_stream_url'] ?? '', $station->id);
        Setting::set('live_alsa_device', $data['live_alsa_device'] ?? 'hw:1,0', $station->id);

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

        Setting::set('rds_rt_mode', $data['rds_rt_mode'], $station->id);
        Setting::set('rds_rt', $data['rds_rt'] ?? '', $station->id);
        Setting::set('rds_ps', $data['rds_ps'] ?? '', $station->id);

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
            'song_id' => ['required', 'integer', 'exists:songs,id'],
        ]);

        $this->queueService->playNow($station->id, (int) $data['song_id']);

        return back()->with('success', 'Song injected at front of queue.');
    }

    public function forceCommercial(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'commercial_id' => ['required', 'integer', 'exists:commercials,id'],
        ]);

        Setting::set('force_commercial_id', $data['commercial_id'], $station->id);

        return back()->with('success', 'Commercial will play on next Pi poll (within 30 s).');
    }

    public function forceSoundByte(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'sound_byte_id' => ['required', 'integer', 'exists:sound_bytes,id'],
        ]);

        Setting::set('force_sound_byte_id', $data['sound_byte_id'], $station->id);

        return back()->with('success', 'Sound byte will play on next Pi poll (within 30 s).');
    }

    public function emergency(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        QueueItem::where('station_id', $station->id)->pending()->update(['status' => 'skipped', 'played_at' => now()]);
        Setting::set('pi_emergency', '1', $station->id);
        PiToken::where('station_id', $station->id)->update(['pi_skip_next' => true]);
        $this->queueService->bumpQueueVersion($station->id);

        return back()->with('success', 'Emergency broadcast triggered — Pi switches within 30 s.');
    }

    /** @return array<string, mixed> */
    private function aggregatePiStatus(Station $station): array
    {
        $tokens = PiToken::where('station_id', $station->id)->get();
        $online = $tokens->filter(fn (PiToken $t) => $t->last_seen_at && $t->last_seen_at->diffInSeconds(now()) < 120);

        $status = 'offline';
        foreach (['live', 'playing', 'idle'] as $candidate) {
            if ($online->contains(fn (PiToken $t) => $t->pi_status === $candidate)) {
                $status = $candidate;
                break;
            }
        }

        $primary = $online->sortByDesc('last_seen_at')->first();

        return [
            'online' => $online->isNotEmpty(),
            'status' => $status,
            'mode' => $primary?->pi_mode ?? 'normal',
            'ip' => $primary?->pi_ip,
            'last_seen' => $tokens->sortByDesc('last_seen_at')->first()?->last_seen_at?->diffForHumans(),
            'device_count' => $tokens->count(),
        ];
    }
}
