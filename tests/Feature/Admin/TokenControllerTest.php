<?php

namespace Tests\Feature\Admin;

use App\Models\PiToken;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_store_creates_a_device_assigned_to_a_station(): void
    {
        $station = Station::create(['name' => 'Station B', 'slug' => 'station-b']);

        $this->actingAs($this->admin)
            ->post('/admin/tokens', ['label' => 'Pi Zero — Car 2', 'station_id' => $station->id])
            ->assertRedirect();

        $this->assertDatabaseHas('pi_tokens', ['label' => 'Pi Zero — Car 2', 'station_id' => $station->id]);
    }

    public function test_regenerate_only_invalidates_the_targeted_token(): void
    {
        ['token' => $tokenA, 'raw' => $rawA] = PiToken::generate('Pi A');
        ['token' => $tokenB] = PiToken::generate('Pi B');
        $originalHashB = $tokenB->fresh()->token_hash;

        $this->actingAs($this->admin)
            ->post("/admin/tokens/{$tokenA->id}/regenerate")
            ->assertRedirect();

        // Token A's old raw value no longer authenticates...
        $this->assertNotSame($rawA, session('new_token'));
        $this->getJson('/api/pi/queue', ['X-Pi-Token' => $rawA])->assertUnauthorized();

        // ...but token B was completely untouched (the bug this replaces: regenerate used to delete ALL tokens).
        $this->assertSame($originalHashB, $tokenB->fresh()->token_hash);
    }

    public function test_update_reassigns_a_device_to_a_different_station(): void
    {
        $stationA = Station::defaultId();
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        ['token' => $token] = PiToken::generate('Pi A', $stationA);

        $this->actingAs($this->admin)
            ->patch("/admin/tokens/{$token->id}", ['station_id' => $stationB->id])
            ->assertRedirect();

        $this->assertSame($stationB->id, $token->fresh()->station_id);
    }

    public function test_destroy_revokes_a_device(): void
    {
        ['token' => $token, 'raw' => $raw] = PiToken::generate('Pi A');

        $this->actingAs($this->admin)
            ->delete("/admin/tokens/{$token->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('pi_tokens', ['id' => $token->id]);
        $this->getJson('/api/pi/queue', ['X-Pi-Token' => $raw])->assertUnauthorized();
    }
}
