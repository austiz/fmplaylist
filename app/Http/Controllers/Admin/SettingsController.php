<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $keys = [
            'frequency',
            'callsign',
            'fallback_song',
            'commercial_interval',
            'sound_byte_interval',
            'fade_in_duration',
        ];
        $settings = Setting::whereIn('key', $keys)->pluck('value', 'key');

        $lastWifiStatus = Setting::get('last_wifi_status', '');
        [$wifiStatusType, $wifiStatusSsid] = str_contains($lastWifiStatus, ':')
            ? explode(':', $lastWifiStatus, 2)
            : ['', ''];

        return Inertia::render('admin/settings', [
            'settings' => $settings,
            'wifi' => [
                'current_ssid'  => Cache::get('pi.wifi_ssid', ''),
                'networks'      => Cache::get('pi.wifi_networks', []),
                'pending_ssid'  => Setting::get('pending_wifi_ssid', ''),
                'last_status'   => $wifiStatusType,   // 'connected' | 'failed' | ''
                'last_ssid'     => $wifiStatusSsid,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'frequency' => ['required', 'numeric', 'min:87.5', 'max:108.0'],
            'callsign' => ['required', 'string', 'max:64'],
            'fallback_song' => ['required', 'string', 'max:255'],
            'commercial_interval' => ['required', 'integer', 'min:0', 'max:50'],
            'sound_byte_interval' => ['required', 'integer', 'min:0', 'max:20'],
            'fade_in_duration' => ['required', 'numeric', 'min:0', 'max:3'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function connectWifi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ssid'     => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:128'],
        ]);

        Setting::set('pending_wifi_ssid', $data['ssid']);
        Setting::set('pending_wifi_password', $data['password'] ?? '');
        Setting::set('last_wifi_status', '');   // clear previous result

        return back()->with('success', 'WiFi change queued. Pi will switch within 30 seconds.');
    }
}
