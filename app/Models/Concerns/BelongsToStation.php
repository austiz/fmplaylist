<?php

namespace App\Models\Concerns;

use App\Models\Scopes\StationScope;
use App\Models\Station;
use App\Support\CurrentStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model that belongs to one station, and is scoped to it without being asked.
 *
 * There were fourteen hand-written `->where('station_id', …)` calls before this,
 * and the tables that had been forgotten -- the media library and chat -- were
 * leaking across stations in production. Scoping you have to remember is scoping
 * you will forget, so it happens here instead.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToStation
{
    public static function bootBelongsToStation(): void
    {
        static::addGlobalScope(new StationScope);

        static::creating(function ($model) {
            if ($model->station_id === null) {
                $model->station_id = CurrentStation::id() ?? Station::defaultId();
            }
        });
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * That station's rows, whichever station the request is about.
     *
     * Services that take a station id as an argument use this: their contract is
     * "this station", not "whatever station happens to be current".
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForStation(Builder $query, int $stationId): Builder
    {
        return $query->withoutGlobalScope(StationScope::class)
            ->where($this->qualifyColumn('station_id'), $stationId);
    }

    /**
     * Every station's rows. Admin listings and scheduled clean-up want this.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAllStations(Builder $query): Builder
    {
        return $query->withoutGlobalScope(StationScope::class);
    }
}
