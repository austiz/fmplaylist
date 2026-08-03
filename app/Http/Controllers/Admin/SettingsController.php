<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\WifiNetwork;
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
                'current_ssid' => Cache::get('pi.wifi_ssid', ''),
                'networks' => Cache::get('pi.wifi_networks', []),
                'pending_ssid' => Setting::get('pending_wifi_ssid', '', $station->id),
                'last_status' => $wifiStatusType,   // 'connected' | 'failed' | ''
                'last_ssid' => $wifiStatusSsid,
                // Saved fallback list. Passwords are never sent to the browser —
                // has_password is enough to render the UI.
                'saved' => WifiNetwork::ordered($station->id)
                    ->map(fn (WifiNetwork $n): array => [
                        'id' => $n->id,
                        'ssid' => $n->ssid,
                        'priority' => $n->priority,
                        'has_password' => (string) $n->password !== '',
                    ])
                    ->all(),
                // Differs while a Pi hasn't picked up the latest edit yet.
                'saved_rev' => WifiNetwork::revisionFor($station->id),
                'pi_rev' => Cache::get('pi.wifi_profiles_rev', ''),
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
            'ssid' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:128'],
        ]);

        Setting::set('pending_wifi_ssid', $data['ssid'], $station->id);
        Setting::set('pending_wifi_password', $data['password'] ?? '', $station->id);
        Setting::set('last_wifi_status', '', $station->id);   // clear previous result

        return back()->with('success', 'WiFi change queued. Pi will switch within 30 seconds.');
    }

    /**
     * Add or update a saved network. Free-text SSID is deliberate: a fallback
     * network is by definition out of range when you configure it, so it can
     * never appear in the Pi's scan list.
     */
    public function storeWifiNetwork(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'ssid' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
        ], [
            'password.min' => 'WPA passwords must be at least 8 characters. Leave blank for an open network.',
        ]);

        $existing = WifiNetwork::where('station_id', $station->id)
            ->where('ssid', $data['ssid'])
            ->first();

        if ($existing) {
            // Re-adding an existing SSID means "fix the password", not "duplicate".
            $existing->update(['password' => $data['password'] ?? null, 'active' => true]);

            return back()->with('success', "Updated \"{$data['ssid']}\".");
        }

        WifiNetwork::create([
            'station_id' => $station->id,
            'ssid' => $data['ssid'],
            'password' => $data['password'] ?? null,
            // Append to the end of the fallback chain.
            'priority' => (int) WifiNetwork::where('station_id', $station->id)->max('priority') + 1,
            'active' => true,
        ]);

        return back()->with('success', "Saved \"{$data['ssid']}\". Pi picks it up within 30 seconds.");
    }

    public function destroyWifiNetwork(Request $request, WifiNetwork $wifiNetwork): RedirectResponse
    {
        $station = $this->activeStation($request);

        abort_unless($wifiNetwork->station_id === $station->id, 404);

        $ssid = $wifiNetwork->ssid;
        $wifiNetwork->delete();

        return back()->with('success', "Removed \"{$ssid}\".");
    }

    /** Reorder the fallback chain. Expects the full ordered list of ids. */
    public function reorderWifiNetworks(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach (array_values($data['ids']) as $index => $id) {
            WifiNetwork::where('station_id', $station->id)
                ->where('id', $id)
                ->update(['priority' => $index]);
        }

        return back()->with('success', 'Network order updated.');
    }

    public function pushDaemonUpdate(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        Setting::set('pi_update_requested', '1', $station->id);

        return back()->with('success', 'Update queued — Pi will refresh the full source payload and restart within 30 seconds.');
    }
}
