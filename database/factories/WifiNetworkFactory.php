<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\WifiNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WifiNetwork> */
class WifiNetworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'ssid' => $this->faker->unique()->domainWord().'-wifi',
            'password' => $this->faker->password(8, 32),
            'priority' => 0,
            'active' => true,
        ];
    }

    /** No password at all, as opposed to a password not yet entered. */
    public function open(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
