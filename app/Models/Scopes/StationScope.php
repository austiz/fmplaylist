<?php

namespace App\Models\Scopes;

use App\Support\CurrentStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows a station-owned model to the station this request is about.
 *
 * Applies only when one is resolved, so console commands and scheduled jobs still
 * see every station. To reach across stations on purpose, ask for it by name:
 * `Model::forStation($id)` or `Model::allStations()` both drop this scope, which
 * makes the cross-station query the loud one rather than the silent one.
 *
 * @implements Scope<Model>
 */
class StationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $stationId = CurrentStation::id();

        if ($stationId !== null) {
            $builder->where($model->qualifyColumn('station_id'), $stationId);
        }
    }
}
