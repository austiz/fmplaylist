<?php

namespace Tests\Feature\Api;

use App\Models\PiToken;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();
        ['raw' => $this->rawToken] = PiToken::generate('Test Pi');
    }

    private function piHeaders(): array
    {
        return ['X-Pi-Token' => $this->rawToken];
    }

    public function test_queue_requires_token(): void
    {
        $this->getJson('/api/pi/queue')->assertUnauthorized();
    }

    public function test_queue_returns_expected_shape(): void
    {
        $response = $this->getJson('/api/pi/queue', $this->piHeaders())->assertOk();

        // QueueService returns: commercial, sound_byte, next
        $response->assertJsonStructure(['commercial', 'sound_byte', 'next']);
    }

    public function test_queue_has_no_station_id_key(): void
    {
        $response = $this->getJson('/api/pi/queue', $this->piHeaders())->assertOk();

        $this->assertArrayNotHasKey('play_station_id', $response->json());
        $this->assertArrayNotHasKey('station_id_available', $response->json());
    }

    public function test_heartbeat_updates_pi_token(): void
    {
        $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
        ], $this->piHeaders())->assertOk();

        $this->assertDatabaseHas('pi_tokens', ['pi_status' => 'idle', 'pi_mode' => 'normal']);
    }

    public function test_heartbeat_returns_config(): void
    {
        $response = $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
        ], $this->piHeaders())->assertOk();

        $response->assertJsonStructure(['freq', 'callsign', 'broadcast_mode']);
    }

    public function test_heartbeat_returns_frequency(): void
    {
        $response = $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
        ], $this->piHeaders())->assertOk();

        $this->assertIsFloat($response->json('freq'));
    }

    public function test_now_playing_public_returns_ok(): void
    {
        // Returns null (200) when nothing is playing
        $this->getJson('/api/now-playing')->assertOk();
    }

    public function test_sync_library_accepts_empty_array(): void
    {
        // Fresh Pi with no songs — must not 422
        $this->postJson('/api/pi/sync-library', ['songs' => []], $this->piHeaders())->assertOk();
    }

    public function test_sync_library_marks_missing_songs_unavailable(): void
    {
        // Song is available in DB but Pi reports it has no songs — DB should mark it unavailable
        $song = Song::factory()->create(['available' => true]);

        $this->postJson('/api/pi/sync-library', ['songs' => []], $this->piHeaders())->assertOk();

        $this->assertFalse((bool) $song->fresh()->available);
    }

    public function test_config_endpoint_returns_expected_keys(): void
    {
        $response = $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();
        $response->assertJsonStructure(['freq', 'callsign', 'broadcast_mode', 'pending_downloads', 'pending_deletes']);
    }

    public function test_config_endpoint_has_no_station_id_interval(): void
    {
        $response = $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();
        $this->assertArrayNotHasKey('station_id_interval', $response->json());
    }
}
