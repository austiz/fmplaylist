<?php

namespace Database\Factories;

use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Station> */
class StationFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->word().' '.$this->faker->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'is_default' => false,
        ];
    }

    /**
     * The station `Station::defaultId()` resolves to. Only one row should carry
     * this flag, so a test wanting a second default must clear the first.
     */
    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
