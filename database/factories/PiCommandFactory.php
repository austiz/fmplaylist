<?php

namespace Database\Factories;

use App\Models\PiCommand;
use App\Models\PiToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PiCommand> */
class PiCommandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pi_token_id' => PiToken::factory(),
            'command' => $this->faker->randomElement(PiCommand::COMMANDS),
            'payload' => null,
            'status' => 'queued',
            'result' => null,
            'sent_at' => null,
            'completed_at' => null,
        ];
    }

    public function command(string $command): static
    {
        return $this->state(fn () => ['command' => $command]);
    }

    /** Handed to the device but not yet acknowledged — the window expireStale() watches. */
    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function acked(string $result = 'ok'): static
    {
        return $this->state(fn () => [
            'status' => 'acked',
            'result' => $result,
            'sent_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $result = 'error'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'result' => $result,
            'sent_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
