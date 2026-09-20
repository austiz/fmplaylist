<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Http\Controllers\Admin\Concerns\HasActiveStation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConnectWifiRequest;
use App\Http\Requests\Admin\StoreWifiNetworkRequest;
use App\Http\Requests\Admin\UpdateStationSettingsRequest;
use App\Models\PiCommand;
use App\Models\Setting;
use App\Models\WifiNetwork;
use App\Support\StationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use HasActiveStation;

    /** The keys this page renders as a form. Order is the order they appear. */
    private const EDITABLE_KEYS = [
        SettingKey::Frequency,
        SettingKey::Callsign,
        SettingKey::FallbackSong,
        SettingKey::CommercialInterval,
        SettingKey::SoundByteInterval,
        SettingKey::FadeInDuration,
    ];

    public function index(Request $request): Response
    {
        $station = $this->activeStation($request);

        $settings = StationSettings::for($station->id);

        $lastWifiStatus = $settings->string(SettingKey::LastWifiStatus);
        [$wifiStatusType, $wifiStatusSsid] = str_contains($lastWifiStatus, ':')
            ? explode(':', $lastWifiStatus, 2)
            : ['', ''];

        return Inertia::render('admin/settings', [
            'settings' => $settings->formValues(self::EDITABLE_KEYS),
            'wifi' => [
                'current_ssid' => Cache::get("pi.wifi_ssid.{$station->id}", ''),
                'networks' => Cache::get("pi.wifi_networks.{$station->id}", []),
                'pending_ssid' => $settings->string(SettingKey::PendingWifiSsid),
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
                'pi_rev' => Cache::get("pi.wifi_profiles_rev.{$station->id}", ''),
            ],
        ]);
    }

    public function update(UpdateStationSettingsRequest $request): RedirectResponse
    {
        $station = $this->activeStation($request);
        $data = $request->validated();

        $settings = StationSettings::for($station->id);

        foreach (self::EDITABLE_KEYS as $key) {
            $settings->put($key, $data[$key->value]);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function connectWifi(ConnectWifiRequest $request): RedirectResponse
    {
        $station = $this->activeStation($request);
        $data = $request->validated();

        Setting::set(SettingKey::PendingWifiSsid, $data['ssid'], $station->id);
        Setting::set(SettingKey::PendingWifiPassword, $data['password'] ?? '', $station->id);
        Setting::set(SettingKey::LastWifiStatus, '', $station->id);   // clear previous result

        return back()->with('success', 'WiFi change queued. Pi will switch within 30 seconds.');
    }

    /**
     * Add or update a saved network. Free-text SSID is deliberate: a fallback
     * network is by definition out of range when you configure it, so it can
     * never appear in the Pi's scan list.
     */
    public function storeWifiNetwork(StoreWifiNetworkRequest $request): RedirectResponse
    {
        $station = $this->activeStation($request);
        $data = $request->validated();

        $existing = WifiNetwork::where('ssid', $data['ssid'])->first();

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
            'priority' => (int) WifiNetwork::max('priority') + 1,
            'active' => true,
        ]);

        return back()->with('success', "Saved \"{$data['ssid']}\". Pi picks it up within 30 seconds.");
    }

    public function destroyWifiNetwork(WifiNetwork $wifiNetwork): RedirectResponse
    {
        $ssid = $wifiNetwork->ssid;
        $wifiNetwork->delete();

        return back()->with('success', "Removed \"{$ssid}\".");
    }

    /** Reorder the fallback chain. Expects the full ordered list of ids. */
    public function reorderWifiNetworks(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach (array_values($data['ids']) as $index => $id) {
            WifiNetwork::where('id', $id)->update(['priority' => $index]);
        }

        return back()->with('success', 'Network order updated.');
    }

    public function pushDaemonUpdate(Request $request): RedirectResponse
    {
        $station = $this->activeStation($request);

        $reached = PiCommand::broadcastTo($station->id, 'update');

        return back()->with('success', $reached === 0
            ? 'No device is registered on this station, so there was nothing to update.'
            : "Update queued for {$reached} device(s) — each refreshes the full source payload and restarts within 30 seconds.");
    }
}
