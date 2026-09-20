<?php

namespace App\Support;

use App\Enums\SettingKey;
use App\Models\Setting;

/**
 * One station's settings, read in a single query.
 *
 * Every `Setting::get()` is its own SELECT, so a Pi heartbeat spent ~21 queries
 * just assembling the config it replies with. Read the bag once and answer from
 * it; `SettingKey` supplies the type and the default (see that enum).
 *
 * Instances are snapshots. Nothing here caches across requests, because the Pi
 * flags are written by one request and read by the next.
 */
final class StationSettings
{
    /** @param  array<string, string>  $raw  stored values, keyed by setting key */
    private function __construct(public readonly int $stationId, private array $raw) {}

    public static function for(int $stationId): self
    {
        return new self(
            $stationId,
            Setting::query()->where('station_id', $stationId)->pluck('value', 'key')->all()
        );
    }

    public function get(SettingKey $key): string|int|float|bool
    {
        $raw = $this->raw[$key->value] ?? null;

        return $raw === null ? $key->default() : $key->cast($raw);
    }

    public function string(SettingKey $key): string
    {
        return (string) $this->get($key);
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function float(SettingKey $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * The stored strings for an Inertia form, with defaults filled in — so the
     * React side does not carry a third copy of every default.
     *
     * @param  list<SettingKey>  $keys
     * @return array<string, string>
     */
    public function formValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key->value] = $key->toStorage($this->get($key));
        }

        return $values;
    }

    /** Persist a value and keep this snapshot consistent with it. */
    public function put(SettingKey $key, mixed $value): void
    {
        Setting::set($key, $value, $this->stationId);
        $this->raw[$key->value] = $key->toStorage($value);
    }
}
