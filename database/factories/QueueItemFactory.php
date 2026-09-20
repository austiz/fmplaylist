<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\QueueItem;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueueItem> */
class QueueItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'media_asset_id' => MediaAsset::factory(),
            'requested_by_name' => null,
            'position' => 1,
            'status' => 'pending',
            'played_at' => null,
        ];
    }

    public function requestedBy(string $name): static
    {
        return $this->state(fn () => ['requested_by_name' => $name]);
    }

    public function playing(): static
    {
        return $this->state(fn () => ['status' => 'playing']);
    }

    public function played(): static
    {
        return $this->state(fn () => [
            'status' => 'played',
            'played_at' => now(),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'played_at' => now(),
        ]);
    }

    /** Positions are per-station and 1-based; QueueService compacts them on play. */
    public function atPosition(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }
}
