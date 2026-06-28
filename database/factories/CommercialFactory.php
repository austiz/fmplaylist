<?php

namespace Database\Factories;

use App\Models\Commercial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Commercial> */
class CommercialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'filename' => $this->faker->uuid().'.wav',
            'storage_path' => null,
            'duration_seconds' => $this->faker->numberBetween(15, 60),
            'file_size' => $this->faker->numberBetween(500_000, 5_000_000),
            'active' => true,
            'rotation_order' => $this->faker->numberBetween(0, 10),
            'play_count' => 0,
            'needs_pi_download' => false,
            'pi_delete_requested' => false,
        ];
    }
}
