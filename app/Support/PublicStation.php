<?php

namespace App\Support;

use App\Models\Station;
use Illuminate\Http\Request;

class PublicStation
{
    /**
     * The station a listener-facing request belongs to: the `?station=` slug when it
     * names a real one, the default otherwise.
     *
     * Also makes it current, so the station-scoped models filter themselves for the
     * rest of the request without every query naming the id again.
     */
    public static function resolve(Request $request): Station
    {
        $slug = $request->query('station');
        $station = null;

        if (is_string($slug) && $slug !== '') {
            $station = Station::where('slug', $slug)->first();
        }

        $station ??= Station::findOrFail(Station::defaultId());

        CurrentStation::set($station);

        return $station;
    }
}
