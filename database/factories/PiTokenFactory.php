<?php

namespace Database\Factories;

use App\Models\PiToken;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PiToken> */
class PiTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'token_hash' => hash('sha256', Str::random(48)),
            'label' => 'Raspberry Pi',
            'last_seen_at' => null,
            'pi_status' => 'offline',
            'pi_mode' => 'normal',
            'pi_ip' => null,
            'pi_skip_next' => false,
            'pi_daemon_hash' => null,
            'disk_free_bytes' => null,
            'disk_total_bytes' => null,
            'pi_fm_running' => null,
            'pi_queue_depth' => null,
            'pi_last_error' => null,
            'pi_last_update_status' => null,
            'pi_last_update_at' => null,
        ];
    }

    /**
     * Only the hash is stored, so a test that needs to authenticate has to know
     * the raw token up front and hand it in here.
     */
    public function withToken(string $raw): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $raw)]);
    }

    /** Seen inside the 120s window the app treats as online. */
    public function online(): static
    {
        return $this->state(fn () => [
            'last_seen_at' => now(),
            'pi_status' => 'playing',
            'pi_ip' => $this->faker->localIpv4(),
            'pi_fm_running' => true,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'last_seen_at' => now()->subHour(),
            'pi_status' => 'offline',
            'pi_fm_running' => false,
        ]);
    }
}
