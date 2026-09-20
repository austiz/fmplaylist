<?php

namespace Database\Factories;

use App\Models\NowPlaying;
use App\Models\Song;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NowPlaying> */
class NowPlayingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'song_id' => Song::factory(),
            'queue_item_id' => null,
            'type' => 'song',
            'started_at' => now(),
        ];
    }

    /** Commercials and sound bytes carry no song_id — the Pi reports only the type. */
    public function ofType(string $type): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'song_id' => $type === 'song' ? Song::factory() : null,
        ]);
    }
}
