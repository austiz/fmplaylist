<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    public function __construct(private QueueService $queueService) {}

    public function index(): Response
    {
        $settingKeys = [
            'broadcast_mode', 'live_stream_url', 'live_alsa_device',
            'rds_rt_mode', 'rds_rt', 'rds_ps',
        ];
        $settings = Setting::whereIn('key', $settingKeys)->pluck('value', 'key');

        $token = PiToken::latest('last_seen_at')->first();
        $online = $token && $token->last_seen_at && $token->last_seen_at->diffInSeconds(now()) < 120;

        $np = NowPlaying::with('song')->find(1);

        return Inertia::render('admin/broadcast', [
            'songs' => Song::available()->orderBy('title')->get(['id', 'title', 'artist', 'duration_seconds']),
            'commercials' => Commercial::active()->orderBy('rotation_order')->orderBy('title')->get(['id', 'title', 'play_count']),
            'soundBytes' => SoundByte::active()->orderBy('category')->orderBy('title')->get(['id', 'title', 'category']),
            'settings' => $settings,
            'pi' => [
                'online' => $online,
                'status' => $token?->pi_status ?? 'offline',
                'mode' => $token?->pi_mode ?? 'normal',
                'ip' => $token?->pi_ip,
                'last_seen' => $token?->last_seen_at?->diffForHumans(),
            ],
            'nowPlaying' => $np ? [
                'title' => $np->song?->title ?? ucwords(str_replace('_', ' ', $np->type ?? '')),
                'artist' => $np->song?->artist,
                'type' => $np->type,
            ] : null,
        ]);
    }

    public function setMode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'broadcast_mode' => ['required', 'in:normal,phone_stream,usb_input,custom_stream'],
            'live_stream_url' => ['nullable', 'string', 'max:255'],
            'live_alsa_device' => ['nullable', 'string', 'max:50'],
        ]);

        Setting::set('broadcast_mode', $data['broadcast_mode']);
        Setting::set('live_stream_url', $data['live_stream_url'] ?? '');
        Setting::set('live_alsa_device', $data['live_alsa_device'] ?? 'hw:1,0');

        return back()->with('success', 'Broadcast mode updated. Pi will switch within 30 seconds.');
    }

    public function updateRds(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rds_rt_mode' => ['required', 'in:auto,custom'],
            'rds_rt' => ['nullable', 'string', 'max:64'],
            'rds_ps' => ['nullable', 'string', 'max:8'],
        ]);

        Setting::set('rds_rt_mode', $data['rds_rt_mode']);
        Setting::set('rds_rt', $data['rds_rt'] ?? '');
        Setting::set('rds_ps', $data['rds_ps'] ?? '');

        return back()->with('success', 'RDS settings saved.');
    }

    public function skip(): RedirectResponse
    {
        $this->queueService->skipCurrent();

        return back()->with('success', 'Current song skipped.');
    }

    public function playNow(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'song_id' => ['required', 'integer', 'exists:songs,id'],
        ]);

        $this->queueService->playNow((int) $data['song_id']);

        return back()->with('success', 'Song injected at front of queue.');
    }

    public function forceCommercial(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'commercial_id' => ['required', 'integer', 'exists:commercials,id'],
        ]);

        Setting::set('force_commercial_id', $data['commercial_id']);

        return back()->with('success', 'Commercial will play on next Pi poll (within 30 s).');
    }

    public function forceSoundByte(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sound_byte_id' => ['required', 'integer', 'exists:sound_bytes,id'],
        ]);

        Setting::set('force_sound_byte_id', $data['sound_byte_id']);

        return back()->with('success', 'Sound byte will play on next Pi poll (within 30 s).');
    }
}
