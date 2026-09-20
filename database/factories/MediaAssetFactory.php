<?php

namespace Database\Factories;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaAsset> */
class MediaAssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => MediaType::Song,
            'title' => $this->faker->words(3, true),
            'artist' => $this->faker->name(),
            'filename' => $this->faker->uuid().'.wav',
            'storage_path' => null,
            'file_size' => $this->faker->numberBetween(5_000_000, 50_000_000),
            'duration_seconds' => $this->faker->numberBetween(120, 300),
            'active' => true,
            'category' => null,
            'rds_ps' => null,
            'rotation_order' => 0,
            'play_count' => 0,
            'needs_pi_download' => false,
            'pi_delete_requested' => false,
        ];
    }

    public function song(): static
    {
        return $this->state(fn () => ['type' => MediaType::Song]);
    }

    /** Commercials carry no artist and are ordered by `rotation_order`. */
    public function commercial(): static
    {
        return $this->state(fn () => [
            'type' => MediaType::Commercial,
            'title' => $this->faker->sentence(3),
            'artist' => null,
            'duration_seconds' => $this->faker->numberBetween(15, 60),
            'file_size' => $this->faker->numberBetween(500_000, 5_000_000),
            'rotation_order' => $this->faker->numberBetween(0, 10),
        ]);
    }

    public function soundByte(): static
    {
        return $this->state(fn () => [
            'type' => MediaType::SoundByte,
            'title' => $this->faker->sentence(2),
            'artist' => null,
            'category' => $this->faker->randomElement(['jingle', 'shoutout', 'drop', 'id']),
            'duration_seconds' => $this->faker->numberBetween(3, 30),
            'file_size' => $this->faker->numberBetween(100_000, 2_000_000),
        ]);
    }

    /** Hidden from the public library, or out of rotation. */
    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** Uploaded through the web UI, so there is a file on the public disk to serve. */
    public function uploaded(string $path = 'songs/example.wav'): static
    {
        return $this->state(fn () => ['storage_path' => $path]);
    }
}
