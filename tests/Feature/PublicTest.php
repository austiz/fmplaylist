<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_loads(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->component('home'));
    }

    public function test_songs_page_loads(): void
    {
        MediaAsset::factory()->count(3)->create(['active' => true]);

        $this->get('/songs')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('songs')->has('songs'));
    }

    public function test_queue_page_loads(): void
    {
        $this->get('/queue')->assertOk()->assertInertia(fn ($p) => $p->component('queue'));
    }

    public function test_song_request_throttles(): void
    {
        $song = MediaAsset::factory()->create(['active' => true]);

        // 5 requests allowed per minute
        for ($i = 0; $i < 5; $i++) {
            $this->post("/songs/{$song->id}/request", ['name' => 'Tester'])->assertRedirect();
        }

        $this->post("/songs/{$song->id}/request", ['name' => 'Tester'])->assertStatus(429);
    }

    public function test_song_request_can_target_public_station_slug(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $song = MediaAsset::factory()->create(['active' => true, 'station_id' => $stationB->id]);

        $this->post("/songs/{$song->id}/request?station=station-b", ['name' => 'Tester'])
            ->assertRedirect();

        $this->assertDatabaseHas('queue_items', [
            'station_id' => $stationB->id,
            'media_asset_id' => $song->id,
            'requested_by_name' => 'Tester',
        ]);
    }

    /**
     * The `{song}` binding runs under the resolved station, so a library id from
     * elsewhere cannot be requested onto this one by guessing it.
     */
    public function test_song_request_cannot_reach_another_stations_library(): void
    {
        Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $songOnDefault = MediaAsset::factory()->create(['active' => true]);

        $this->post("/songs/{$songOnDefault->id}/request?station=station-b", ['name' => 'Tester'])
            ->assertNotFound();

        $this->assertDatabaseMissing('queue_items', ['media_asset_id' => $songOnDefault->id]);
    }

    public function test_songs_page_shares_public_station_context(): void
    {
        Station::create(['name' => 'Station B', 'slug' => 'station-b']);

        $this->get('/songs?station=station-b')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('station.slug', 'station-b'));
    }

    public function test_frequency_is_shared_on_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('frequency'));
    }
}
