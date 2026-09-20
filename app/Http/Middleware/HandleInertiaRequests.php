<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\Station;
use App\Support\PublicStation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Resolved independently of EnsureActiveStation's request attribute: this global
        // middleware group runs before route-specific middleware, so that attribute isn't
        // set yet when share() executes. Mirrors EnsureActiveStation's own fallback logic.
        $activeStation = $request->user() ? $this->resolveActiveStation() : null;
        $publicStation = $request->user() ? null : PublicStation::resolve($request);
        $frequencyStationId = $activeStation->id ?? $publicStation?->id;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => ['user' => $request->user()],
            'frequency' => Setting::get('frequency', '96.9', $frequencyStationId),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'activeStation' => $activeStation ? ['id' => $activeStation->id, 'name' => $activeStation->name, 'slug' => $activeStation->slug] : null,
            'publicStation' => $publicStation ? ['id' => $publicStation->id, 'name' => $publicStation->name, 'slug' => $publicStation->slug] : null,
            'stations' => $activeStation ? Station::orderBy('name')->get(['id', 'name', 'slug']) : null,
        ];
    }

    private function resolveActiveStation(): ?Station
    {
        $stationId = session('active_station_id');

        return ($stationId ? Station::find((int) $stationId) : null) ?? Station::orderBy('id')->first();
    }
}
