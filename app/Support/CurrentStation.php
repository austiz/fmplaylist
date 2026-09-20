<?php

namespace App\Support;

use App\Models\Station;

/**
 * Which station this request is about.
 *
 * Every request reaches exactly one station, but it gets there three different
 * ways: an admin session (EnsureActiveStation), a `?station=` slug on a listener
 * page (PublicStation), or the token a device presents (AuthenticatePiToken).
 * Each of those sets this, and `BelongsToStation` reads it so the scoping is
 * applied once rather than remembered at every query.
 *
 * Nothing sets it in the console or in a test that hasn't made a request, and
 * that is deliberate: a scheduled job legitimately works across every station.
 *
 * It lives for the process, not the request, which under PHP-FPM is the same
 * thing. Anything that reuses a process across requests -- Octane, and the test
 * suite, which is why `TestCase` forgets it in setUp -- has to reset it.
 */
class CurrentStation
{
    private static ?int $id = null;

    public static function set(Station|int|null $station): void
    {
        self::$id = $station instanceof Station ? $station->id : $station;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    /** Runs $callback as if $station were the current one, then restores. */
    public static function as(Station|int|null $station, callable $callback): mixed
    {
        $previous = self::$id;
        self::set($station);

        try {
            return $callback();
        } finally {
            self::$id = $previous;
        }
    }

    public static function forget(): void
    {
        self::$id = null;
    }
}
