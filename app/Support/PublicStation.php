<?php

namespace App\Support;

use App\Models\Station;
use Illuminate\Http\Request;

class PublicStation
{
    public static function resolve(Request $request): Station
    {
        $slug = $request->query('station');

        if (is_string($slug) && $slug !== '') {
            $station = Station::where('slug', $slug)->first();
            if ($station) {
                return $station;
            }
        }

        return Station::findOrFail(Station::defaultId());
    }
}
