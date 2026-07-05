<?php

namespace Tests\Feature\Api;

use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Song;
use App\Models\Station;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_queue_next_requested_by_name_is_null_for_autofilled_song(): void
    {
        // Auto-filled queue items (no requester) must send an explicit JSON null,
        // not omit the key — the Pi daemon relies on the key always being present.
        $song = Song::factory()->create(['available' => true]);
        QueueItem::create([
            'station_id' => \App\Models\Station::defaultId(),
            'song_id' => $song->id,
            'requested_by_name' => null,
            'position' => 1,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/pi/queue', $this->piHeaders())->assertOk();

        $this->assertArrayHasKey('requested_by_name', $response->json('next'));
        $response->assertJsonPath('next.requested_by_name', null);
    }

    public function test_queue_next_requested_by_name_is_present_for_requested_song(): void
    {
        $song = Song::factory()->create(['available' => true]);
        QueueItem::create([
            'station_id' => \App\Models\Station::defaultId(),
            'song_id' => $song->id,
            'requested_by_name' => 'Alex',
            'position' => 1,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/pi/queue', $this->piHeaders())->assertOk();

        $response->assertJsonPath('next.requested_by_name', 'Alex');
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

    public function test_two_devices_on_different_stations_get_different_queues(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        ['raw' => $rawB] = PiToken::generate('Pi B', $stationB->id);

        $songA = Song::factory()->create(['available' => true]);
        QueueItem::create([
            'station_id' => Station::defaultId(),
            'song_id' => $songA->id,
            'position' => 1,
            'status' => 'pending',
        ]);

        $songB = Song::factory()->create(['available' => true]);
        QueueItem::create([
            'station_id' => $stationB->id,
            'song_id' => $songB->id,
            'position' => 1,
            'status' => 'pending',
        ]);

        $responseA = $this->getJson('/api/pi/queue', $this->piHeaders())->assertOk();
        $responseB = $this->getJson('/api/pi/queue', ['X-Pi-Token' => $rawB])->assertOk();

        $this->assertSame($songA->id, $responseA->json('next.song.id'));
        $this->assertSame($songB->id, $responseB->json('next.song.id'));
    }

    public function test_confirm_download_is_per_device(): void
    {
        $song = Song::factory()->create(['available' => true, 'needs_pi_download' => true]);
        ['raw' => $rawB] = PiToken::generate('Pi B');

        // Device A confirms the download...
        $this->postJson('/api/pi/confirm-download', ['type' => 'song', 'item_id' => $song->id], $this->piHeaders())
            ->assertOk();

        // ...but device B, which never confirmed it, must still see it as pending.
        $responseB = $this->getJson('/api/pi/config', ['X-Pi-Token' => $rawB])->assertOk();
        $pendingIdsB = collect($responseB->json('pending_downloads'))->pluck('item_id')->all();
        $this->assertContains($song->id, $pendingIdsB);

        $responseA = $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();
        $pendingIdsA = collect($responseA->json('pending_downloads'))->pluck('item_id')->all();
        $this->assertNotContains($song->id, $pendingIdsA);
    }

    public function test_confirm_delete_only_purges_after_all_devices_confirm(): void
    {
        $song = Song::factory()->create(['available' => true, 'pi_delete_requested' => true]);
        ['raw' => $rawB] = PiToken::generate('Pi B');

        // Both devices have this song locally.
        $this->postJson('/api/pi/confirm-download', ['type' => 'song', 'item_id' => $song->id], $this->piHeaders())->assertOk();
        $this->postJson('/api/pi/confirm-download', ['type' => 'song', 'item_id' => $song->id], ['X-Pi-Token' => $rawB])->assertOk();

        // Device A confirms the delete — the row must survive since device B still holds it.
        $this->postJson('/api/pi/confirm-delete', ['type' => 'song', 'item_id' => $song->id], $this->piHeaders())->assertOk();
        $this->assertDatabaseHas('songs', ['id' => $song->id]);

        // Device B confirms too — now that no device holds it, the row is purged.
        $this->postJson('/api/pi/confirm-delete', ['type' => 'song', 'item_id' => $song->id], ['X-Pi-Token' => $rawB])->assertOk();
        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
    }

    public function test_heartbeat_persists_disk_stats(): void
    {
        $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
            'disk_free_bytes' => 1_000_000,
            'disk_total_bytes' => 8_000_000,
        ], $this->piHeaders())->assertOk();

        $this->assertDatabaseHas('pi_tokens', [
            'disk_free_bytes' => 1_000_000,
            'disk_total_bytes' => 8_000_000,
        ]);
    }

    public function test_heartbeat_succeeds_when_optional_columns_are_missing(): void
    {
        Schema::drop('pi_tokens');
        Schema::create('pi_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->nullable();
            $table->string('token_hash', 64);
            $table->string('label')->default('Raspberry Pi');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->string('pi_status', 20)->default('offline');
            $table->string('pi_mode', 30)->default('normal');
            $table->string('pi_ip', 45)->nullable();
            $table->boolean('pi_skip_next')->default(false);
        });

        ['raw' => $rawToken] = PiToken::generate('Legacy Pi');

        $response = $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
        ], ['X-Pi-Token' => $rawToken]);

        $response->assertOk();
        $this->assertDatabaseHas('pi_tokens', ['pi_status' => 'idle', 'pi_mode' => 'normal']);
    }
}
