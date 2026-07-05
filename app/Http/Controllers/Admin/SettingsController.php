<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use HasActiveStation;

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $keys = [
            'frequency',
            'callsign',
            'fallback_song',
            'commercial_interval',
            'sound_byte_interval',
            'fade_in_duration',
        ];
        $settings = Setting::whereIn('key', $keys)->where('station_id', $station->id)->pluck('value', 'key');

        $lastWifiStatus = Setting::get('last_wifi_status', '', $station->id);
        [$wifiStatusType, $wifiStatusSsid] = str_contains($lastWifiStatus, ':')
            ? explode(':', $lastWifiStatus, 2)
            : ['', ''];

        return Inertia::render('admin/settings', [
            'settings' => $settings,
            'wifi' => [
                'current_ssid'  => Cache::get('pi.wifi_ssid', ''),
                'networks'      => Cache::get('pi.wifi_networks', []),
                'pending_ssid'  => Setting::get('pending_wifi_ssid', '', $station->id),
                'last_status'   => $wifiStatusType,   // 'connected' | 'failed' | ''
                'last_ssid'     => $wifiStatusSsid,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'frequency' => ['required', 'numeric', 'min:87.5', 'max:108.0'],
            'callsign' => ['required', 'string', 'max:64'],
            'fallback_song' => ['required', 'string', 'max:255'],
            'commercial_interval' => ['required', 'integer', 'min:0', 'max:50'],
            'sound_byte_interval' => ['required', 'integer', 'min:0', 'max:20'],
            'fade_in_duration' => ['required', 'numeric', 'min:0', 'max:3'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value, $station->id);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function connectWifi(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'ssid'     => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:128'],
        ]);

        Setting::set('pending_wifi_ssid', $data['ssid'], $station->id);
        Setting::set('pending_wifi_password', $data['password'] ?? '', $station->id);
        Setting::set('last_wifi_status', '', $station->id);   // clear previous result

        return back()->with('success', 'WiFi change queued. Pi will switch within 30 seconds.');
    }

    public function pushDaemonUpdate(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        Setting::set('pi_update_requested', '1', $station->id);

        return back()->with('success', 'Update queued — Pi will pull the latest daemon and restart within 30 seconds.');
    }
}
