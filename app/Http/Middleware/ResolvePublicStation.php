<?php

namespace App\Http\Middleware;

use App\Support\PublicStation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the listener's station current before anything else in the request runs.
 *
 * `PublicStation::resolve()` called from a controller body is too late for route-model
 * binding: `/songs/{song}/request?station=b` would bind the song unscoped, so a song
 * belonging to station A could be queued onto station B by id. Resolving here -- and
 * ahead of `SubstituteBindings` in the priority list -- means `{song}` is looked up
 * under `StationScope` and a foreign id 404s.
 *
 * Controllers still call `PublicStation::resolve()` for the Station model itself; it is
 * idempotent, and having the value returned where it is used reads better than pulling
 * it back out of the request.
 */
class ResolvePublicStation
{
    public function handle(Request $request, Closure $next): Response
    {
        PublicStation::resolve($request);

        return $next($request);
    }
}
