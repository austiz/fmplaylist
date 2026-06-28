<?php

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Song> */
class SongFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->words(3, true),
            'artist' => $this->faker->name(),
            'filename' => $this->faker->uuid().'.wav',
            'duration_seconds' => $this->faker->numberBetween(120, 300),
            'file_size' => $this->faker->numberBetween(5_000_000, 50_000_000),
            'available' => true,
            'storage_path' => null,
            'needs_pi_download' => false,
            'pi_delete_requested' => false,
        ];
    }
}
