<?php

namespace Tests\Feature\Api;

use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Station;
use App\Services\DeviceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * The device config used to be assembled with one SELECT per setting, so a Pi
     * poll spent sixteen queries reading its own config. StationSettings loads the
     * bag once; this keeps it that way.
     */
    public function test_the_device_config_reads_the_settings_bag_once(): void
    {
        DB::enableQueryLog();

        $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();

        $settingsQueries = array_filter(
            $this->loggedQueries(),
            fn (string $q) => str_contains($q, 'from settings')
        );

        $this->assertCount(1, $settingsQueries);
    }

    /**
     * `syncLibrary()` used to run a lookup and a firstOrCreate for every filename the
     * device reported, so a Pi with a 500-song card spent a thousand round trips in one
     * request. The cost is now flat in the size of the report.
     */
    public function test_sync_library_cost_does_not_grow_with_the_library(): void
    {
        $songs = MediaAsset::factory()->count(40)->create();
        $payload = ['songs' => $songs->map(fn (MediaAsset $s) => ['filename' => $s->filename])->all()];

        DB::enableQueryLog();

        $this->postJson('/api/pi/sync-library', $payload, $this->piHeaders())
            ->assertOk()
            ->assertJson(['added' => 40, 'unchanged' => 0]);

        $this->assertLessThan(10, count(DB::getQueryLog()));

        // And a second identical report is the same handful of queries, recording nothing new.
        DB::flushQueryLog();

        $this->postJson('/api/pi/sync-library', $payload, $this->piHeaders())
            ->assertOk()
            ->assertJson(['added' => 0, 'unchanged' => 40]);

        $this->assertLessThan(10, count(DB::getQueryLog()));
        $this->assertSame(40, DeviceDownload::where('pi_token_id', PiToken::first()->id)->count());
    }

    public function test_sync_library_ignores_the_daemons_own_runtime_files(): void
    {
        $song = MediaAsset::factory()->create();

        $this->postJson('/api/pi/sync-library', ['songs' => [
            ['filename' => $song->filename],
            ['filename' => 'emergency.wav'],
        ]], $this->piHeaders())->assertOk()->assertJson(['added' => 1]);

        $this->assertSame(1, DeviceDownload::count());
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
        $song = MediaAsset::factory()->create(['active' => true]);
        QueueItem::create([
            'station_id' => Station::defaultId(),
            'media_asset_id' => $song->id,
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
        $song = MediaAsset::factory()->create(['active' => true]);
        QueueItem::create([
            'station_id' => Station::defaultId(),
            'media_asset_id' => $song->id,
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

    public function test_sync_library_does_not_mark_missing_songs_unavailable(): void
    {
        $song = MediaAsset::factory()->create(['active' => true]);

        $this->postJson('/api/pi/sync-library', ['songs' => []], $this->piHeaders())->assertOk();

        $this->assertTrue((bool) $song->fresh()->active);
    }

    public function test_second_device_empty_sync_does_not_affect_global_song_availability(): void
    {
        $song = MediaAsset::factory()->create(['active' => true]);
        ['raw' => $rawB] = PiToken::generate('Pi B');

        $this->postJson('/api/pi/sync-library', ['songs' => []], ['X-Pi-Token' => $rawB])->assertOk();

        $this->assertTrue((bool) $song->fresh()->active);
    }

    public function test_sync_library_ignores_runtime_files_without_creating_songs(): void
    {
        $song = MediaAsset::factory()->create(['active' => true]);

        $this->postJson('/api/pi/sync-library', [
            'songs' => [
                ['filename' => 'FTPA.wav', 'file_size' => 123],
                ['filename' => 'station_id.wav', 'file_size' => 456],
            ],
        ], $this->piHeaders())->assertOk();

        $this->assertTrue((bool) $song->fresh()->active);
        $this->assertDatabaseMissing('media_assets', ['filename' => 'FTPA.wav']);
        $this->assertDatabaseMissing('media_assets', ['filename' => 'station_id.wav']);
    }

    public function test_sync_library_records_known_song_for_this_device_only(): void
    {
        $song = MediaAsset::factory()->create(['active' => true, 'filename' => 'known-song.wav']);

        $this->postJson('/api/pi/sync-library', [
            'songs' => [
                ['filename' => 'known-song.wav', 'file_size' => 123],
            ],
        ], $this->piHeaders())->assertOk();

        $token = PiToken::findByRaw($this->rawToken);
        $this->assertDatabaseHas('device_downloads', [
            'pi_token_id' => $token->id,
            'media_type' => 'song',
            'media_id' => $song->id,
        ]);
        $this->assertTrue((bool) $song->fresh()->active);
    }

    public function test_config_endpoint_returns_expected_keys(): void
    {
        $response = $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();
        $response->assertJsonStructure(['freq', 'callsign', 'broadcast_mode', 'pending_downloads', 'pending_deletes']);
    }

    public function test_config_does_not_send_downloads_without_storage_path(): void
    {
        MediaAsset::factory()->create([
            'active' => true,
            'storage_path' => null,
        ]);

        $response = $this->getJson('/api/pi/config', $this->piHeaders())->assertOk();

        $this->assertSame([], $response->json('pending_downloads'));
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

        $songA = MediaAsset::factory()->create(['active' => true, 'station_id' => Station::defaultId()]);
        QueueItem::create([
            'station_id' => Station::defaultId(),
            'media_asset_id' => $songA->id,
            'position' => 1,
            'status' => 'pending',
        ]);

        $songB = MediaAsset::factory()->create(['active' => true, 'station_id' => $stationB->id]);
        QueueItem::create([
            'station_id' => $stationB->id,
            'media_asset_id' => $songB->id,
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
        $song = MediaAsset::factory()->create([
            'active' => true,
            'needs_pi_download' => true,
            'storage_path' => 'songs/example.wav',
        ]);
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

    /**
     * Daemons in the field predate the type discriminator and still post a bare
     * `song_id`. Both confirm endpoints share one request class now, so this
     * fallback exists once rather than twice.
     */
    public function test_a_legacy_confirm_without_a_type_is_read_as_a_song(): void
    {
        $song = MediaAsset::factory()->create([
            'active' => true,
            'needs_pi_download' => true,
            'storage_path' => 'songs/example.wav',
        ]);

        $this->postJson('/api/pi/confirm-download', ['song_id' => $song->id], $this->piHeaders())
            ->assertOk();

        $this->assertDatabaseHas('device_downloads', [
            'media_type' => 'song',
            'media_id' => $song->id,
        ]);
    }

    public function test_confirm_delete_only_purges_after_all_devices_confirm(): void
    {
        $song = MediaAsset::factory()->create(['active' => true, 'pi_delete_requested' => true]);
        ['raw' => $rawB] = PiToken::generate('Pi B');

        // Both devices have this song locally.
        $this->postJson('/api/pi/confirm-download', ['type' => 'song', 'item_id' => $song->id], $this->piHeaders())->assertOk();
        $this->postJson('/api/pi/confirm-download', ['type' => 'song', 'item_id' => $song->id], ['X-Pi-Token' => $rawB])->assertOk();

        // Device A confirms the delete — the row must survive since device B still holds it.
        $this->postJson('/api/pi/confirm-delete', ['type' => 'song', 'item_id' => $song->id], $this->piHeaders())->assertOk();
        $this->assertDatabaseHas('media_assets', ['id' => $song->id]);

        // Device B confirms too — now that no device holds it, the row is purged.
        $this->postJson('/api/pi/confirm-delete', ['type' => 'song', 'item_id' => $song->id], ['X-Pi-Token' => $rawB])->assertOk();
        $this->assertDatabaseMissing('media_assets', ['id' => $song->id]);
    }

    public function test_delete_requested_legacy_song_without_device_downloads_is_purged(): void
    {
        $song = MediaAsset::factory()->create([
            'active' => false,
            'pi_delete_requested' => true,
        ]);

        // Scheduled housekeeping, not the device poll — see routes/console.php.
        app(DeviceSyncService::class)->purgeOrphanedDeleteRequests();

        $this->assertDatabaseMissing('media_assets', ['id' => $song->id]);
    }

    public function test_pi_status_update_available_checks_all_online_devices(): void
    {
        $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
            'daemon_hash' => 'stalehash',
        ], $this->piHeaders())->assertOk();

        ['raw' => $rawB, 'token' => $tokenB] = PiToken::generate('Fresh Pi');
        $tokenB->update([
            'last_seen_at' => now(),
            'pi_daemon_hash' => null,
        ]);

        $this->postJson('/api/pi/heartbeat', [
            'status' => 'idle',
            'mode' => 'normal',
        ], ['X-Pi-Token' => $rawB])->assertOk();

        $this->getJson('/api/live')
            ->assertOk()
            ->assertJsonPath('pi_status.update_available', true);
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
}
