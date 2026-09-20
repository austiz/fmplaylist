<?php

namespace Tests\Feature\Api;

use App\Models\ChatMessage;
use App\Models\Station;
use App\Support\LiveState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The live transport's contract, which the SSE stream it replaced had no tests for.
 *
 * What matters here is the cursor: a client that already has the current state must
 * get a few bytes back, and any change to any watched value must move the cursor so
 * the next poll carries the payload.
 */
class LiveControllerTest extends TestCase
{
    use RefreshDatabase;

    private function poll(array $query = []): TestResponse
    {
        return $this->getJson('/api/live?'.http_build_query($query));
    }

    public function test_the_first_poll_returns_the_whole_state(): void
    {
        $response = $this->poll()->assertOk();

        $response->assertJsonStructure([
            'v', 'listeners', 'poll_seconds',
            'now_playing', 'pi_status', 'queue_version', 'chat',
        ]);

        $this->assertSame(LiveState::POLL_SECONDS, $response->json('poll_seconds'));
    }

    public function test_an_unchanged_cursor_returns_no_payload(): void
    {
        $cursor = $this->poll()->json('v');

        $response = $this->poll(['v' => $cursor])->assertOk();

        $this->assertSame($cursor, $response->json('v'));
        $this->assertSame(
            ['listeners', 'poll_seconds', 'v'],
            collect(array_keys($response->json()))->sort()->values()->all()
        );
    }

    public function test_a_queue_change_moves_the_cursor_and_sends_the_payload(): void
    {
        $stationId = Station::defaultId();
        $cursor = $this->poll()->json('v');

        LiveState::queueChanged($stationId);

        $response = $this->poll(['v' => $cursor])->assertOk();

        $this->assertNotSame($cursor, $response->json('v'));
        $response->assertJsonStructure(['now_playing', 'pi_status', 'queue_version', 'chat']);
    }

    public function test_now_playing_and_pi_status_come_back_as_they_were_published(): void
    {
        $stationId = Station::defaultId();

        LiveState::nowPlayingChanged($stationId, ['song' => ['id' => 7, 'title' => 'Test']]);
        LiveState::piStatusChanged($stationId, ['online' => true, 'status' => 'playing']);

        $response = $this->poll()->assertOk();

        $this->assertSame(7, $response->json('now_playing.song.id'));
        $this->assertTrue($response->json('pi_status.online'));
    }

    public function test_the_chat_seed_is_the_newest_messages_in_reading_order(): void
    {
        foreach (range(1, 60) as $i) {
            ChatMessage::create(['name' => 'Listener', 'message' => "msg {$i}"]);
        }

        $chat = $this->poll()->assertOk()->json('chat');

        $this->assertCount(50, $chat);
        // Newest 50, oldest-first: taking the oldest 50 instead pinned the chat to the
        // first conversation the station ever had.
        $this->assertSame('msg 11', $chat[0]['message']);
        $this->assertSame('msg 60', $chat[49]['message']);
    }

    public function test_since_returns_only_what_the_client_has_not_seen(): void
    {
        $old = ChatMessage::create(['name' => 'A', 'message' => 'first']);
        $new = ChatMessage::create(['name' => 'B', 'message' => 'second']);

        LiveState::chatChanged(Station::defaultId());

        $chat = $this->poll(['since' => $old->id])->assertOk()->json('chat');

        $this->assertCount(1, $chat);
        $this->assertSame($new->id, $chat[0]['id']);
    }

    public function test_another_stations_chat_does_not_leak_in(): void
    {
        $other = Station::factory()->create();
        ChatMessage::create(['station_id' => $other->id, 'name' => 'Elsewhere', 'message' => 'not ours']);
        ChatMessage::create(['name' => 'Ours', 'message' => 'ours']);

        $chat = $this->poll()->assertOk()->json('chat');

        $this->assertSame(['ours'], collect($chat)->pluck('message')->all());
    }

    public function test_each_station_carries_its_own_cursor(): void
    {
        $other = Station::factory()->create();

        $default = $this->poll()->json('v');
        $elsewhere = $this->poll(['station' => $other->slug])->json('v');

        LiveState::queueChanged($other->id);

        // The other station moved; ours did not.
        $this->assertSame($default, $this->poll()->json('v'));
        $this->assertNotSame($elsewhere, $this->poll(['station' => $other->slug])->json('v'));
    }

    public function test_listeners_are_counted_by_client_rather_than_by_request(): void
    {
        $this->poll(['c' => 'tab-one']);
        $this->poll(['c' => 'tab-one']);

        $this->assertSame(1, $this->poll(['c' => 'tab-one'])->json('listeners'));
        $this->assertSame(2, $this->poll(['c' => 'tab-two'])->json('listeners'));
    }

    public function test_a_poll_without_a_client_id_does_not_add_a_listener(): void
    {
        $this->poll(['c' => 'a-real-tab']);

        // This is what the admin status bar does: it watches the station without
        // being part of its audience.
        $this->assertSame(1, $this->poll()->json('listeners'));
    }

    public function test_the_listener_count_stays_out_of_the_cursor(): void
    {
        $cursor = $this->poll(['c' => 'tab-one'])->json('v');

        // Otherwise every arriving and departing tab would invalidate everyone's
        // cursor and the poll would send the full payload every time.
        $this->assertSame($cursor, $this->poll(['c' => 'tab-two', 'v' => $cursor])->json('v'));
    }
}
