<?php

namespace App\Models;

use App\Enums\SettingKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A string KV bag of per-station settings.
 *
 * Keys are `SettingKey` cases rather than free strings, and that enum owns each
 * key's type and default, so a read comes back typed. To read more than one key
 * at a time use `StationSettings`, which loads the whole bag in one query — every
 * call here is its own SELECT.
 */
class Setting extends Model
{
    protected $fillable = ['station_id', 'key', 'value'];

    public static function get(SettingKey $key, ?int $stationId = null): string|int|float|bool
    {
        $row = static::query()
            ->where('station_id', $stationId ?? Station::defaultId())
            ->where('key', $key->value)
            ->first();

        return $row ? $key->cast((string) $row->value) : $key->default();
    }

    public static function set(SettingKey $key, mixed $value, ?int $stationId = null): void
    {
        static::updateOrCreate(
            ['station_id' => $stationId ?? Station::defaultId(), 'key' => $key->value],
            ['value' => $key->toStorage($value)]
        );
    }

    public static function inc(SettingKey $key, int $by = 1, ?int $stationId = null): void
    {
        $stationId ??= Station::defaultId();

        // firstOrCreate only inserts when the row is missing — does not overwrite existing values
        static::firstOrCreate(['station_id' => $stationId, 'key' => $key->value], ['value' => '0']);
        static::where('station_id', $stationId)->where('key', $key->value)->increment('value', $by);
    }
}
