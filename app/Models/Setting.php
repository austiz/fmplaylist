<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['station_id', 'key', 'value'];

    public static function get(string $key, mixed $default = null, ?int $stationId = null): mixed
    {
        $row = static::query()
            ->where('station_id', $stationId ?? Station::defaultId())
            ->where('key', $key)
            ->first();

        return $row ? $row->value : $default;
    }

    public static function set(string $key, mixed $value, ?int $stationId = null): void
    {
        static::updateOrCreate(
            ['station_id' => $stationId ?? Station::defaultId(), 'key' => $key],
            ['value' => (string) $value]
        );
    }

    public static function inc(string $key, int $by = 1, ?int $stationId = null): void
    {
        $stationId ??= Station::defaultId();

        // firstOrCreate only inserts when the row is missing — does not overwrite existing values
        static::firstOrCreate(['station_id' => $stationId, 'key' => $key], ['value' => '0']);
        static::where('station_id', $stationId)->where('key', $key)->increment('value', $by);
    }
}
