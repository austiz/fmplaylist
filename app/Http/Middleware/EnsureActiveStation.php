<?php

namespace App\Http\Middleware;

use App\Models\Station;
use App\Support\CurrentStation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStation
{
    /** Routes reachable even with zero stations, so a fresh install can create the first one. */
    private const ALLOWED_WITHOUT_STATION = ['admin.stations', 'admin.stations.store'];

    public function handle(Request $request, Closure $next): Response
    {
        $stationId = (int) session('active_station_id');
        $station = $stationId ? Station::find($stationId) : null;

        if (! $station) {
            $station = Station::orderBy('id')->first();

            if ($station) {
                session(['active_station_id' => $station->id]);
            }
        }

        if (! $station) {
            if (in_array($request->route()?->getName(), self::ALLOWED_WITHOUT_STATION, true)) {
                return $next($request);
            }

            return redirect()->route('admin.stations')
                ->with('status', 'Create a station to get started.');
        }

        $request->attributes->set('active_station', $station);
        CurrentStation::set($station);

        return $next($request);
    }
}
