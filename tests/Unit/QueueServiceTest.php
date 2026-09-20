<?php

namespace Tests\Unit;

use App\Models\Commercial;
use App\Models\DeviceDownload;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Models\Station;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Characterization tests: these pin QueueService's behaviour *as it is today*,
 * including the two known faults Phase 4 will fix (getNextForPi() handing the
 * same item to two devices, and markNowPlaying() retiring every playing row).
 * Those cases are marked below — when the fix lands the test must change, and
 * it should change deliberately rather than quietly.
 */
class QueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private QueueService $service;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(QueueService::class);
        $this->station = Station::findOrFail(Station::defaultId());
    }

    // -- addToQueue --------------------------------------------------------

    public function test_add_to_queue_appends_after_the_last_pending_item(): void
    {
        QueueItem::factory()->for($this->station)->atPosition(4)->create();

        $item = $this->service->addToQueue(
            $this->station->id,
            Song::factory()->create()->id,
            'Dana',
        );

        $this->assertSame(5, $item->position);
        $this->assertSame('pending', $item->status);
        $this->assertSame('Dana', $item->requested_by_name);
    }

    public function test_add_to_queue_ignores_played_items_when_numbering(): void
    {
        // Only pending rows count toward max(position) — a played item parked at
        // a high position must not push the next request past it.
        QueueItem::factory()->for($this->station)->atPosition(9)->played()->create();

        $item = $this->service->addToQueue($this->station->id, Song::factory()->create()->id, null);

        $this->assertSame(1, $item->position);
    }

    public function test_add_to_queue_numbers_positions_per_station(): void
    {
        $other = Station::factory()->create();
        QueueItem::factory()->for($other)->atPosition(7)->create();

        $item = $this->service->addToQueue($this->station->id, Song::factory()->create()->id, null);

        $this->assertSame(1, $item->position);
    }

    public function test_add_to_queue_bumps_the_queue_version(): void
    {
        $key = "sse.queue_version.{$this->station->id}";
        Cache::forget($key);

        $this->service->addToQueue($this->station->id, Song::factory()->create()->id, null);

        $this->assertNotNull(Cache::get($key));
    }

    // -- peekUpcoming ------------------------------------------------------

    public function test_peek_upcoming_skips_the_next_item_and_honours_the_limit(): void
    {
        $items = collect(range(1, 5))->map(fn (int $position) => QueueItem::factory()
            ->for($this->station)
            ->atPosition($position)
            ->create());

        $peeked = $this->service->peekUpcoming($this->station->id, 2);

        // Item 1 is what getNextForPi() already returned, so peek starts at item 2.
        $this->assertSame(
            [$items[1]->id, $items[2]->id],
            array_column($peeked, 'queue_item_id'),
        );
        $this->assertSame($items[1]->song->filename, $peeked[0]['song']['filename']);
    }

    public function test_peek_upcoming_is_scoped_to_its_station(): void
    {
        $other = Station::factory()->create();
        QueueItem::factory()->for($other)->count(4)->create();

        $this->assertSame([], $this->service->peekUpcoming($this->station->id));
    }

    // -- getNextForPi: autofill --------------------------------------------

    public function test_get_next_for_pi_autofills_up_to_the_configured_target(): void
    {
        config(['fm.autofill_target' => 3]);
        Song::factory()->count(5)->create();

        $this->service->getNextForPi($this->station->id);

        $this->assertSame(3, QueueItem::where('station_id', $this->station->id)->pending()->count());
    }

    public function test_autofill_skips_songs_already_pending_or_playing_on_the_station(): void
    {
        config(['fm.autofill_target' => 3]);
        $queued = Song::factory()->create();
        $playing = Song::factory()->create();
        Song::factory()->count(5)->create();

        QueueItem::factory()->for($this->station)->for($queued)->atPosition(1)->create();
        QueueItem::factory()->for($this->station)->for($playing)->playing()->create();

        $this->service->getNextForPi($this->station->id);

        $pendingSongIds = QueueItem::where('station_id', $this->station->id)->pending()->pluck('song_id');
        $this->assertSame(1, $pendingSongIds->filter(fn ($id) => $id === $queued->id)->count());
        $this->assertFalse($pendingSongIds->contains($playing->id));
    }

    public function test_autofill_ignores_unavailable_songs(): void
    {
        config(['fm.autofill_target' => 5]);
        Song::factory()->count(3)->create(['available' => false]);

        $this->service->getNextForPi($this->station->id);

        $this->assertSame(0, QueueItem::where('station_id', $this->station->id)->count());
    }

    public function test_autofill_leaves_a_full_queue_alone(): void
    {
        config(['fm.autofill_target' => 2]);
        QueueItem::factory()->for($this->station)->atPosition(1)->create();
        QueueItem::factory()->for($this->station)->atPosition(2)->create();
        Song::factory()->count(5)->create();

        $this->service->getNextForPi($this->station->id);

        $this->assertSame(2, QueueItem::where('station_id', $this->station->id)->count());
    }

    // -- getNextForPi: selection -------------------------------------------

    public function test_get_next_for_pi_returns_the_lowest_positioned_pending_item(): void
    {
        config(['fm.autofill_target' => 0]);
        $second = QueueItem::factory()->for($this->station)->atPosition(2)->create();
        $first = QueueItem::factory()->for($this->station)->atPosition(1)->create();

        $next = $this->service->getNextForPi($this->station->id)['next'];

        $this->assertSame($first->id, $next['queue_item_id']);
        $this->assertNotSame($second->id, $next['queue_item_id']);
        $this->assertSame($first->song->duration_seconds, $next['song']['duration_seconds']);
    }

    /**
     * KNOWN FAULT (Phase 4). `$next` is read outside any lock and nothing claims
     * it, so two devices polling the same station are handed the same item. This
     * pins the current behaviour; the fix should make it fail loudly.
     */
    public function test_get_next_for_pi_currently_hands_the_same_item_to_repeated_callers(): void
    {
        config(['fm.autofill_target' => 0]);
        QueueItem::factory()->for($this->station)->atPosition(1)->create();
        QueueItem::factory()->for($this->station)->atPosition(2)->create();

        $first = $this->service->getNextForPi($this->station->id)['next']['queue_item_id'];
        $second = $this->service->getNextForPi($this->station->id)['next']['queue_item_id'];

        $this->assertSame($first, $second);
    }

    // -- getNextForPi: commercials -----------------------------------------

    public function test_a_forced_commercial_wins_over_the_interval(): void
    {
        config(['fm.autofill_target' => 0]);
        $forced = Commercial::factory()->create();
        Commercial::factory()->create();
        Setting::set('force_commercial_id', $forced->id, $this->station->id);

        $result = $this->service->getNextForPi($this->station->id);

        $this->assertSame($forced->id, $result['commercial']['id']);
        $this->assertSame($forced->filename, $result['commercial']['filename']);
    }

    public function test_an_inactive_forced_commercial_is_not_returned(): void
    {
        config(['fm.autofill_target' => 0]);
        $forced = Commercial::factory()->create(['active' => false]);
        Setting::set('force_commercial_id', $forced->id, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    public function test_a_commercial_is_scheduled_once_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        $commercial = Commercial::factory()->create(['rotation_order' => 1]);
        Setting::set('commercial_interval', 4, $this->station->id);
        Setting::set('songs_since_last_commercial', 4, $this->station->id);

        $this->assertSame(
            $commercial->id,
            $this->service->getNextForPi($this->station->id)['commercial']['id'],
        );
    }

    public function test_no_commercial_before_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        Commercial::factory()->create();
        Setting::set('commercial_interval', 4, $this->station->id);
        Setting::set('songs_since_last_commercial', 3, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    public function test_a_zero_interval_disables_commercial_scheduling(): void
    {
        config(['fm.autofill_target' => 0]);
        Commercial::factory()->create();
        Setting::set('commercial_interval', 0, $this->station->id);
        Setting::set('songs_since_last_commercial', 99, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    // -- getNextForPi: sound bytes -----------------------------------------

    public function test_a_forced_sound_byte_is_returned_with_its_rds_text(): void
    {
        config(['fm.autofill_target' => 0]);
        $forced = SoundByte::factory()->create(['category' => 'drop', 'rds_ps' => 'DROP']);
        Setting::set('force_sound_byte_id', $forced->id, $this->station->id);

        $soundByte = $this->service->getNextForPi($this->station->id)['sound_byte'];

        $this->assertSame($forced->id, $soundByte['id']);
        $this->assertSame('drop', $soundByte['category']);
        $this->assertSame('DROP', $soundByte['rds_ps']);
    }

    public function test_a_sound_byte_is_scheduled_once_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        $soundByte = SoundByte::factory()->create();
        Setting::set('sound_byte_interval', 2, $this->station->id);
        Setting::set('songs_since_last_sound_byte', 2, $this->station->id);

        $this->assertSame(
            $soundByte->id,
            $this->service->getNextForPi($this->station->id)['sound_byte']['id'],
        );
    }

    public function test_sound_byte_settings_are_read_per_station(): void
    {
        config(['fm.autofill_target' => 0]);
        $other = Station::factory()->create();
        SoundByte::factory()->create();
        Setting::set('sound_byte_interval', 2, $other->id);
        Setting::set('songs_since_last_sound_byte', 9, $other->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['sound_byte']);
        $this->assertNotNull($this->service->getNextForPi($other->id)['sound_byte']);
    }

    // -- markNowPlaying ----------------------------------------------------

    public function test_marking_a_song_playing_records_it_and_advances_the_counters(): void
    {
        $song = Song::factory()->create();
        $item = QueueItem::factory()->for($this->station)->for($song)->atPosition(1)->create();
        Setting::set('songs_since_last_commercial', 2, $this->station->id);
        Setting::set('songs_since_last_sound_byte', 5, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, $song->filename);

        $np = NowPlaying::forStation($this->station->id);
        $this->assertSame('song', $np->type);
        $this->assertSame($song->id, $np->song_id);
        $this->assertSame($item->id, $np->queue_item_id);
        $this->assertSame('playing', $item->fresh()->status);
        $this->assertSame(3, (int) Setting::get('songs_since_last_commercial', 0, $this->station->id));
        $this->assertSame(6, (int) Setting::get('songs_since_last_sound_byte', 0, $this->station->id));
    }

    public function test_marking_a_song_playing_compacts_the_remaining_positions(): void
    {
        $item = QueueItem::factory()->for($this->station)->atPosition(1)->create();
        $second = QueueItem::factory()->for($this->station)->atPosition(6)->create();
        $third = QueueItem::factory()->for($this->station)->atPosition(9)->create();

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, null);

        $this->assertSame(1, $second->fresh()->position);
        $this->assertSame(2, $third->fresh()->position);
    }

    public function test_an_unknown_filename_leaves_now_playing_without_a_song(): void
    {
        $this->service->markNowPlaying($this->station->id, 'song', null, 'not-in-the-library.wav');

        $np = NowPlaying::forStation($this->station->id);
        $this->assertSame('song', $np->type);
        $this->assertNull($np->song_id);
    }

    /**
     * KNOWN FAULT (Phase 4). The update is keyed on status alone, so a report
     * about one item retires every playing row on the station — including a
     * replayed POST retiring the song that just started. Pinned deliberately.
     */
    public function test_mark_now_playing_currently_retires_every_playing_row(): void
    {
        $unrelated = QueueItem::factory()->for($this->station)->playing()->create();
        $item = QueueItem::factory()->for($this->station)->atPosition(1)->create();

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, null);

        $this->assertSame('played', $unrelated->fresh()->status);
        $this->assertNotNull($unrelated->fresh()->played_at);
    }

    public function test_mark_now_playing_does_not_touch_another_stations_queue(): void
    {
        $other = Station::factory()->create();
        $otherPlaying = QueueItem::factory()->for($other)->playing()->create();

        $this->service->markNowPlaying($this->station->id, 'song', null, null);

        $this->assertSame('playing', $otherPlaying->fresh()->status);
    }

    public function test_a_played_commercial_resets_its_counters_and_records_the_play(): void
    {
        $commercial = Commercial::factory()->create(['play_count' => 4]);
        Setting::set('songs_since_last_commercial', 7, $this->station->id);
        Setting::set('force_commercial_id', $commercial->id, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $commercial->id);

        $this->assertSame(0, (int) Setting::get('songs_since_last_commercial', null, $this->station->id));
        $this->assertSame(0, (int) Setting::get('force_commercial_id', null, $this->station->id));
        $this->assertSame($commercial->id, (int) Setting::get('last_commercial_id', null, $this->station->id));
        $this->assertSame(5, $commercial->fresh()->play_count);
        $this->assertSame('commercial', NowPlaying::forStation($this->station->id)->type);
    }

    public function test_a_played_sound_byte_resets_its_counters(): void
    {
        Setting::set('songs_since_last_sound_byte', 6, $this->station->id);
        Setting::set('force_sound_byte_id', 12, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'sound_byte', null, null);

        $this->assertSame(0, (int) Setting::get('songs_since_last_sound_byte', null, $this->station->id));
        $this->assertSame(0, (int) Setting::get('force_sound_byte_id', null, $this->station->id));
        $this->assertSame('sound_byte', NowPlaying::forStation($this->station->id)->type);
    }

    public function test_mark_now_playing_publishes_the_live_frame(): void
    {
        $song = Song::factory()->create();

        $this->service->markNowPlaying($this->station->id, 'song', null, $song->filename);

        $frame = Cache::get("sse.now_playing.{$this->station->id}");
        $this->assertSame('song', $frame['type']);
        $this->assertSame($song->title, $frame['song']['title']);
        $this->assertSame($song->duration_seconds, $frame['song']['duration_seconds']);
    }

    // -- skipCurrent / playNow ---------------------------------------------

    public function test_skip_current_retires_only_the_playing_item(): void
    {
        $playing = QueueItem::factory()->for($this->station)->playing()->create();
        $pending = QueueItem::factory()->for($this->station)->atPosition(1)->create();

        $this->service->skipCurrent($this->station->id);

        $this->assertSame('skipped', $playing->fresh()->status);
        $this->assertNotNull($playing->fresh()->played_at);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_play_now_jumps_the_queue_and_skips_what_was_playing(): void
    {
        $playing = QueueItem::factory()->for($this->station)->playing()->create();
        $queued = QueueItem::factory()->for($this->station)->atPosition(1)->create();
        $song = Song::factory()->create();

        $item = $this->service->playNow($this->station->id, $song->id);

        $this->assertSame(1, $item->position);
        $this->assertSame($song->id, $item->song_id);
        $this->assertSame('Admin', $item->requested_by_name);
        $this->assertSame(2, $queued->fresh()->position);
        $this->assertSame('skipped', $playing->fresh()->status);
    }

    public function test_play_now_keeps_an_explicit_requester_name(): void
    {
        $item = $this->service->playNow($this->station->id, Song::factory()->create()->id, 'Jules');

        $this->assertSame('Jules', $item->requested_by_name);
    }

    // -- syncLibrary -------------------------------------------------------

    public function test_sync_library_records_new_downloads_and_counts_repeats(): void
    {
        $token = PiToken::factory()->for($this->station)->create();
        $known = Song::factory()->create();
        $alreadyHad = Song::factory()->create();
        DeviceDownload::factory()->for($token)->forMedia($alreadyHad, 'song')->create();

        $result = $this->service->syncLibrary($token, [
            ['filename' => $known->filename],
            ['filename' => $alreadyHad->filename],
        ]);

        $this->assertSame(['added' => 1, 'unchanged' => 1], $result);
        $this->assertDatabaseHas('device_downloads', [
            'pi_token_id' => $token->id,
            'media_type' => 'song',
            'media_id' => $known->id,
        ]);
    }

    public function test_sync_library_ignores_runtime_files_and_unknown_names(): void
    {
        $token = PiToken::factory()->for($this->station)->create();

        $result = $this->service->syncLibrary($token, [
            ['filename' => 'FTPA.wav'],
            ['filename' => 'station_id.wav'],
            ['filename' => '../../etc/passwd'],
            ['filename' => ''],
            ['filename' => 'never-uploaded.wav'],
        ]);

        $this->assertSame(['added' => 0, 'unchanged' => 0], $result);
        $this->assertSame(0, DeviceDownload::count());
    }
}
