<?php

namespace Database\Factories;

use App\Models\SoundByte;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SoundByte> */
class SoundByteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(2),
            'filename' => $this->faker->uuid().'.wav',
            'category' => $this->faker->randomElement(['jingle', 'shoutout', 'drop', 'id']),
            'storage_path' => null,
            'duration_seconds' => $this->faker->numberBetween(3, 30),
            'file_size' => $this->faker->numberBetween(100_000, 2_000_000),
            'active' => true,
            'needs_pi_download' => false,
            'pi_delete_requested' => false,
        ];
    }
}
