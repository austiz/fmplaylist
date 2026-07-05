<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stations', [
            'stations' => Station::orderBy('name')->get(['id', 'name', 'slug', 'is_default']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $slug = Str::slug($data['name']);
        $unique = $slug;
        $suffix = 2;
        while (Station::where('slug', $unique)->exists()) {
            $unique = "{$slug}-{$suffix}";
            $suffix++;
        }

        $station = Station::create(['name' => $data['name'], 'slug' => $unique]);

        session(['active_station_id' => $station->id]);

        return redirect()->route('admin.dashboard')->with('success', "Station \"{$station->name}\" created.");
    }

    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'station_id' => ['required', 'integer', 'exists:stations,id'],
        ]);

        session(['active_station_id' => $data['station_id']]);

        return back();
    }
}
