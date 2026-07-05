<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Station;
use Illuminate\Http\Request;

trait HasActiveStation
{
    /** Resolved by EnsureActiveStation middleware — always present on routes using this trait. */
    protected function activeStation(Request $request): Station
    {
        return $request->attributes->get('active_station');
    }
}
