<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Two stations, one server, nothing crossing between them.
 *
 * Media, chat and the listener count used to be global: the library, the chat log and
 * the listener-count cache key were shared by every station on the install. Each
 * test here pins one of those, plus the route-model binding that reaches the same rows
 * by id rather than by listing them.
 */
class MultiStationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Station $a;

    private Station $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Station::findOrFail(Station::defaultId());
        $this->b = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
    }

    public function test_the_song_library_does_not_cross_stations(): void
    {
        MediaAsset::factory()->create(['station_id' => $this->a->id, 'title' => 'Only On A', 'active' => true]);
        MediaAsset::factory()->create(['station_id' => $this->b->id, 'title' => 'Only On B', 'active' => true]);

        $this->get('/songs?station=station-b')
            ->assertOk()
            ->assertSee('Only On B')
            ->assertDontSee('Only On A');
    }

    public function test_chat_does_not_cross_stations(): void
    {
        ChatMessage::factory()->create(['station_id' => $this->a->id, 'message' => 'hello from A']);
        ChatMessage::factory()->create(['station_id' => $this->b->id, 'message' => 'hello from B']);

        $onB = $this->getJson('/api/chat?station=station-b')->assertOk()->json();

        $this->assertCount(1, $onB);
        $this->assertSame('hello from B', $onB[0]['message']);
    }

    public function test_a_posted_chat_message_lands_on_the_station_it_was_sent_from(): void
    {
        $this->postJson('/api/chat?station=station-b', ['name' => 'Tester', 'message' => 'for B only'])
            ->assertCreated();

        $this->assertDatabaseHas('chat_messages', [
            'station_id' => $this->b->id,
            'message' => 'for B only',
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'station_id' => $this->a->id,
            'message' => 'for B only',
        ]);
    }

    /**
     * Every other SSE cache key was already suffixed with the station id; these two
     * were not, so one station's chat bumped the other's stream and both stations
     * showed one combined listener tally.
     */
    public function test_the_live_cache_keys_are_per_station(): void
    {
        Cache::forget("live.chat_version.{$this->a->id}");
        Cache::forget("live.chat_version.{$this->b->id}");

        $this->postJson('/api/chat?station=station-b', ['name' => 'Tester', 'message' => 'bump B'])
            ->assertCreated();

        $this->assertNotNull(Cache::get("live.chat_version.{$this->b->id}"));
        $this->assertNull(Cache::get("live.chat_version.{$this->a->id}"));
    }

    public function test_now_playing_does_not_cross_stations(): void
    {
        $songA = MediaAsset::factory()->create(['station_id' => $this->a->id, 'title' => 'On Air At A']);
        NowPlaying::factory()->create(['station_id' => $this->a->id, 'media_asset_id' => $songA->id]);

        // Asserting the absence of the key rather than the empty body: `json(null)`
        // encodes as `{}`, which is a wart worth fixing but not one to pin here.
        $this->getJson('/api/now-playing?station=station-b')->assertOk()->assertJsonMissingPath('song');
        $this->getJson('/api/now-playing')->assertOk()->assertJsonPath('song.title', 'On Air At A');
    }

    public function test_pi_status_does_not_report_another_stations_device(): void
    {
        PiToken::factory()->for($this->a)->create(['last_seen_at' => now()]);

        $this->getJson('/api/pi-status?station=station-b')
            ->assertOk()
            ->assertJsonPath('online', false);

        $this->getJson('/api/pi-status')
            ->assertOk()
            ->assertJsonPath('online', true);
    }

    public function test_the_queue_page_shows_only_its_own_station(): void
    {
        $songA = MediaAsset::factory()->create(['station_id' => $this->a->id, 'title' => 'Queued On A']);
        QueueItem::factory()->for($this->a)->create(['media_asset_id' => $songA->id]);

        $songB = MediaAsset::factory()->create(['station_id' => $this->b->id, 'title' => 'Queued On B']);
        QueueItem::factory()->for($this->b)->create(['media_asset_id' => $songB->id]);

        $this->get('/queue?station=station-b')
            ->assertOk()
            ->assertSee('Queued On B')
            ->assertDontSee('Queued On A');
    }
}
