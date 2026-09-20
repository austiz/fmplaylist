<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\Station;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "What is on air" used to be serialized in six places in three shapes: the REST
 * and SSE payloads for the same event disagreed, the Pi's public endpoint omitted
 * duration_seconds, and the admin dashboard returned null for commercials so they
 * rendered as "nothing playing". These pin the one shape.
 */
class NowPlayingShapeTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['type', 'song', 'queue_item_id', 'started_at'];

    private const SONG_KEYS = ['id', 'title', 'artist', 'duration_seconds'];

    public function test_every_surface_serializes_a_song_the_same_way(): void
    {
        $station = Station::find(Station::defaultId());
        $song = MediaAsset::factory()->create(['title' => 'On Air', 'artist' => 'The Band', 'duration_seconds' => 184]);
        NowPlaying::create([
            'station_id' => $station->id,
            'media_asset_id' => $song->id,
            'type' => 'song',
            'started_at' => now(),
        ]);

        $expected = [
            'type' => 'song',
            'song' => ['id' => $song->id, 'title' => 'On Air', 'artist' => 'The Band', 'duration_seconds' => 184],
            'queue_item_id' => null,
        ];

        foreach ($this->surfaces() as $label => $payload) {
            $this->assertSame(self::KEYS, array_keys($payload), "{$label} key set");
            $this->assertSame(self::SONG_KEYS, array_keys($payload['song']), "{$label} song key set");
            $this->assertSame($expected, Arr::except($payload, 'started_at'), "{$label} payload");
        }
    }

    public function test_a_commercial_is_announced_rather_than_dropped(): void
    {
        $station = Station::find(Station::defaultId());
        NowPlaying::create(['station_id' => $station->id, 'type' => 'commercial', 'started_at' => now()]);

        foreach ($this->surfaces() as $label => $payload) {
            $this->assertSame('commercial', $payload['type'], "{$label} type");
            $this->assertSame('Commercial Break', $payload['song']['title'], "{$label} title");
            $this->assertNull($payload['song']['id'], "{$label} id");
        }
    }

    public function test_the_live_frame_matches_the_rest_payload(): void
    {
        $station = Station::find(Station::defaultId());
        $song = MediaAsset::factory()->create(['duration_seconds' => 200]);

        app(QueueService::class)->markNowPlaying($station->id, 'song', null, $song->filename);

        $frame = Cache::get("sse.now_playing.{$station->id}");

        $this->assertSame($this->surfaces()['api'], $frame);
    }

    /**
     * The same now-playing row as each surface renders it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function surfaces(): array
    {
        $admin = User::factory()->create();

        $home = $this->get('/')->assertOk();
        $queue = $this->get('/queue')->assertOk();
        $dashboard = $this->actingAs($admin)->get('/admin')->assertOk();

        return [
            'api' => $this->getJson('/api/now-playing')->assertOk()->json(),
            'home' => $home->viewData('page')['props']['nowPlaying'],
            'queue' => $queue->viewData('page')['props']['nowPlaying'],
            'dashboard' => $dashboard->viewData('page')['props']['nowPlaying'],
        ];
    }
}
