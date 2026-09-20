<?php

namespace App\Http\Middleware;

use App\Enums\SettingKey;
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
        // Closures, not values: a partial reload -- which is how the queue page and the
        // devices page refresh themselves -- drops the props it did not ask for *before*
        // resolving them, so these four cost nothing on the polls. As plain values they
        // were four queries on every one.
        //
        // Resolved independently of EnsureActiveStation's request attribute: this global
        // middleware group runs before route-specific middleware, so that attribute isn't
        // set yet when share() executes. Mirrors EnsureActiveStation's own fallback logic.
        $memo = [];
        $station = function (string $which) use ($request, &$memo): ?Station {
            $memo = $memo ?: ($request->user()
                ? ['active' => $this->resolveActiveStation(), 'public' => null]
                : ['active' => null, 'public' => PublicStation::resolve($request)]);

            return $memo[$which];
        };

        $summarize = fn (?Station $s) => $s
            ? ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug]
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => ['user' => $request->user()],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Rendered, not computed with — the shared prop is a display string.
            'frequency' => function () use ($station) {
                $current = $station('active') ?? $station('public');

                return (string) Setting::get(SettingKey::Frequency, $current?->id);
            },
            'activeStation' => fn () => $summarize($station('active')),
            'publicStation' => fn () => $summarize($station('public')),
            'stations' => fn () => $station('active')
                ? Station::orderBy('name')->get(['id', 'name', 'slug'])
                : null,
        ];
    }

    private function resolveActiveStation(): ?Station
    {
        $stationId = session('active_station_id');

        return ($stationId ? Station::find((int) $stationId) : null) ?? Station::orderBy('id')->first();
    }
}
