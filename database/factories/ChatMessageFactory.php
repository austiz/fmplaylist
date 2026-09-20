<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatMessage> */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->firstName(),
            'message' => $this->faker->sentence(),
        ];
    }
}
