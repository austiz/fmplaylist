<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? $row->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }

    public static function inc(string $key, int $by = 1): void
    {
        // firstOrCreate only inserts when the row is missing — does not overwrite existing values
        static::firstOrCreate(['key' => $key], ['value' => '0']);
        static::where('key', $key)->increment('value', $by);
    }
}
