<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return Inertia::render('admin/settings', [
            'settings' => $settings,
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
}
