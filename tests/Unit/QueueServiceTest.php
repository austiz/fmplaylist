<?php

namespace Tests\Unit;

use App\Enums\SettingKey;
use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Station;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The queue's behaviour, pinned. Written first as characterization tests so the
 * Phase 4 concurrency work had something to land against; the two cases that
 * pinned known faults have since been replaced by the behaviour that fixed them.
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
            MediaAsset::factory()->create()->id,
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

        $item = $this->service->addToQueue($this->station->id, MediaAsset::factory()->create()->id, null);

        $this->assertSame(1, $item->position);
    }

    public function test_add_to_queue_numbers_positions_per_station(): void
    {
        $other = Station::factory()->create();
        QueueItem::factory()->for($other)->atPosition(7)->create();

        $item = $this->service->addToQueue($this->station->id, MediaAsset::factory()->create()->id, null);

        $this->assertSame(1, $item->position);
    }

    public function test_add_to_queue_bumps_the_queue_version(): void
    {
        $key = "sse.queue_version.{$this->station->id}";
        Cache::forget($key);

        $this->service->addToQueue($this->station->id, MediaAsset::factory()->create()->id, null);

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
        $this->assertSame($items[1]->mediaAsset->filename, $peeked[0]['song']['filename']);
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
        MediaAsset::factory()->count(5)->create();

        $this->service->getNextForPi($this->station->id);

        $this->assertSame(3, QueueItem::where('station_id', $this->station->id)->pending()->count());
    }

    public function test_autofill_skips_songs_already_pending_or_playing_on_the_station(): void
    {
        config(['fm.autofill_target' => 3]);
        $queued = MediaAsset::factory()->create();
        $playing = MediaAsset::factory()->create();
        MediaAsset::factory()->count(5)->create();

        QueueItem::factory()->for($this->station)->for($queued)->atPosition(1)->create();
        QueueItem::factory()->for($this->station)->for($playing)->playing()->create();

        $this->service->getNextForPi($this->station->id);

        $pendingSongIds = QueueItem::where('station_id', $this->station->id)->pending()->pluck('media_asset_id');
        $this->assertSame(1, $pendingSongIds->filter(fn ($id) => $id === $queued->id)->count());
        $this->assertFalse($pendingSongIds->contains($playing->id));
    }

    public function test_autofill_ignores_unavailable_songs(): void
    {
        config(['fm.autofill_target' => 5]);
        MediaAsset::factory()->count(3)->create(['active' => false]);

        $this->service->getNextForPi($this->station->id);

        $this->assertSame(0, QueueItem::where('station_id', $this->station->id)->count());
    }

    public function test_autofill_leaves_a_full_queue_alone(): void
    {
        config(['fm.autofill_target' => 2]);
        QueueItem::factory()->for($this->station)->atPosition(1)->create();
        QueueItem::factory()->for($this->station)->atPosition(2)->create();
        MediaAsset::factory()->count(5)->create();

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
        $this->assertSame($first->mediaAsset->duration_seconds, $next['song']['duration_seconds']);
    }

    /**
     * Deliberate, not a race. A station is one programme on one frequency, and its
     * devices are transmitters of that programme -- which is the whole premise of
     * broadcasting an emergency to all of them. Claiming the item per device would
     * put two transmitters on the same frequency playing different songs.
     */
    public function test_every_device_on_a_station_is_handed_the_same_next_item(): void
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
        $forced = MediaAsset::factory()->commercial()->create();
        MediaAsset::factory()->commercial()->create();
        Setting::set(SettingKey::ForceCommercialId, $forced->id, $this->station->id);

        $result = $this->service->getNextForPi($this->station->id);

        $this->assertSame($forced->id, $result['commercial']['id']);
        $this->assertSame($forced->filename, $result['commercial']['filename']);
    }

    public function test_an_inactive_forced_commercial_is_not_returned(): void
    {
        config(['fm.autofill_target' => 0]);
        $forced = MediaAsset::factory()->commercial()->create(['active' => false]);
        Setting::set(SettingKey::ForceCommercialId, $forced->id, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    public function test_a_commercial_is_scheduled_once_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        $commercial = MediaAsset::factory()->commercial()->create(['rotation_order' => 1]);
        Setting::set(SettingKey::CommercialInterval, 4, $this->station->id);
        Setting::set(SettingKey::SongsSinceLastCommercial, 4, $this->station->id);

        $this->assertSame(
            $commercial->id,
            $this->service->getNextForPi($this->station->id)['commercial']['id'],
        );
    }

    public function test_no_commercial_before_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        MediaAsset::factory()->commercial()->create();
        Setting::set(SettingKey::CommercialInterval, 4, $this->station->id);
        Setting::set(SettingKey::SongsSinceLastCommercial, 3, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    public function test_a_zero_interval_disables_commercial_scheduling(): void
    {
        config(['fm.autofill_target' => 0]);
        MediaAsset::factory()->commercial()->create();
        Setting::set(SettingKey::CommercialInterval, 0, $this->station->id);
        Setting::set(SettingKey::SongsSinceLastCommercial, 99, $this->station->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['commercial']);
    }

    // -- getNextForPi: sound bytes -----------------------------------------

    public function test_a_forced_sound_byte_is_returned_with_its_rds_text(): void
    {
        config(['fm.autofill_target' => 0]);
        $forced = MediaAsset::factory()->soundByte()->create(['category' => 'drop', 'rds_ps' => 'DROP']);
        Setting::set(SettingKey::ForceSoundByteId, $forced->id, $this->station->id);

        $soundByte = $this->service->getNextForPi($this->station->id)['sound_byte'];

        $this->assertSame($forced->id, $soundByte['id']);
        $this->assertSame('drop', $soundByte['category']);
        $this->assertSame('DROP', $soundByte['rds_ps']);
    }

    public function test_a_sound_byte_is_scheduled_once_the_interval_is_reached(): void
    {
        config(['fm.autofill_target' => 0]);
        $soundByte = MediaAsset::factory()->soundByte()->create();
        Setting::set(SettingKey::SoundByteInterval, 2, $this->station->id);
        Setting::set(SettingKey::SongsSinceLastSoundByte, 2, $this->station->id);

        $this->assertSame(
            $soundByte->id,
            $this->service->getNextForPi($this->station->id)['sound_byte']['id'],
        );
    }

    public function test_sound_byte_settings_are_read_per_station(): void
    {
        config(['fm.autofill_target' => 0]);
        $other = Station::factory()->create();
        MediaAsset::factory()->soundByte()->create(['station_id' => $other->id]);
        Setting::set(SettingKey::SoundByteInterval, 2, $other->id);
        Setting::set(SettingKey::SongsSinceLastSoundByte, 9, $other->id);

        $this->assertNull($this->service->getNextForPi($this->station->id)['sound_byte']);
        $this->assertNotNull($this->service->getNextForPi($other->id)['sound_byte']);
    }

    // -- markNowPlaying ----------------------------------------------------

    public function test_marking_a_song_playing_records_it_and_advances_the_counters(): void
    {
        $song = MediaAsset::factory()->create();
        $item = QueueItem::factory()->for($this->station)->for($song)->atPosition(1)->create();
        Setting::set(SettingKey::SongsSinceLastCommercial, 2, $this->station->id);
        Setting::set(SettingKey::SongsSinceLastSoundByte, 5, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, $song->filename);

        $np = NowPlaying::forStation($this->station->id);
        $this->assertSame('song', $np->type);
        $this->assertSame($song->id, $np->media_asset_id);
        $this->assertSame($item->id, $np->queue_item_id);
        $this->assertSame('playing', $item->fresh()->status);
        $this->assertSame(3, (int) Setting::get(SettingKey::SongsSinceLastCommercial, $this->station->id));
        $this->assertSame(6, (int) Setting::get(SettingKey::SongsSinceLastSoundByte, $this->station->id));
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
        $this->assertNull($np->media_asset_id);
    }

    public function test_mark_now_playing_retires_what_was_on_air(): void
    {
        $outgoing = QueueItem::factory()->for($this->station)->playing()->create();
        $item = QueueItem::factory()->for($this->station)->atPosition(1)->create();

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, null);

        $this->assertSame('played', $outgoing->fresh()->status);
        $this->assertNotNull($outgoing->fresh()->played_at);
    }

    /**
     * The update used to be keyed on status alone, so the report about an item
     * retired that item too -- it was set to `playing` a line later, but a replay
     * arriving after the next song had started retired the *new* song.
     */
    public function test_mark_now_playing_does_not_retire_the_item_it_is_reporting(): void
    {
        $item = QueueItem::factory()->for($this->station)->playing()->create();

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, null);

        $this->assertSame('playing', $item->fresh()->status);
        $this->assertNull($item->fresh()->played_at);
    }

    /**
     * The daemon fires these from a detached thread and retries, and a second
     * transmitter on the station reports the same song. Either way the rotation
     * counters must advance once.
     */
    public function test_a_repeated_report_for_the_same_song_is_ignored(): void
    {
        $song = MediaAsset::factory()->create();
        $item = QueueItem::factory()->for($this->station)->atPosition(1)->create(['media_asset_id' => $song->id]);
        Setting::set(SettingKey::SongsSinceLastCommercial, 0, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'song', $item->id, $song->filename);
        $this->service->markNowPlaying($this->station->id, 'song', $item->id, $song->filename);
        $this->service->markNowPlaying($this->station->id, 'song', $item->id, $song->filename);

        $this->assertSame(1, (int) Setting::get(SettingKey::SongsSinceLastCommercial, $this->station->id));
        $this->assertSame('playing', $item->fresh()->status);
    }

    public function test_a_repeated_commercial_report_does_not_double_count_the_play(): void
    {
        $commercial = MediaAsset::factory()->commercial()->create(['play_count' => 0]);

        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $commercial->id);
        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $commercial->id);

        $this->assertSame(1, $commercial->fresh()->play_count);
    }

    public function test_a_different_commercial_is_still_recorded_as_a_new_segment(): void
    {
        $first = MediaAsset::factory()->commercial()->create(['play_count' => 0]);
        $second = MediaAsset::factory()->commercial()->create(['play_count' => 0]);

        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $first->id);
        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $second->id);

        $this->assertSame(1, $first->fresh()->play_count);
        $this->assertSame(1, $second->fresh()->play_count);
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
        $commercial = MediaAsset::factory()->commercial()->create(['play_count' => 4]);
        Setting::set(SettingKey::SongsSinceLastCommercial, 7, $this->station->id);
        Setting::set(SettingKey::ForceCommercialId, $commercial->id, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'commercial', null, null, $commercial->id);

        $this->assertSame(0, (int) Setting::get(SettingKey::SongsSinceLastCommercial, $this->station->id));
        $this->assertSame(0, (int) Setting::get(SettingKey::ForceCommercialId, $this->station->id));
        $this->assertSame($commercial->id, (int) Setting::get(SettingKey::LastCommercialId, $this->station->id));
        $this->assertSame(5, $commercial->fresh()->play_count);
        $this->assertSame('commercial', NowPlaying::forStation($this->station->id)->type);
    }

    public function test_a_played_sound_byte_resets_its_counters(): void
    {
        Setting::set(SettingKey::SongsSinceLastSoundByte, 6, $this->station->id);
        Setting::set(SettingKey::ForceSoundByteId, 12, $this->station->id);

        $this->service->markNowPlaying($this->station->id, 'sound_byte', null, null);

        $this->assertSame(0, (int) Setting::get(SettingKey::SongsSinceLastSoundByte, $this->station->id));
        $this->assertSame(0, (int) Setting::get(SettingKey::ForceSoundByteId, $this->station->id));
        $this->assertSame('sound_byte', NowPlaying::forStation($this->station->id)->type);
    }

    public function test_mark_now_playing_publishes_the_live_frame(): void
    {
        $song = MediaAsset::factory()->create();

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
        $song = MediaAsset::factory()->create();

        $item = $this->service->playNow($this->station->id, $song->id);

        $this->assertSame(1, $item->position);
        $this->assertSame($song->id, $item->media_asset_id);
        $this->assertSame('Admin', $item->requested_by_name);
        $this->assertSame(2, $queued->fresh()->position);
        $this->assertSame('skipped', $playing->fresh()->status);
    }

    public function test_play_now_keeps_an_explicit_requester_name(): void
    {
        $item = $this->service->playNow($this->station->id, MediaAsset::factory()->create()->id, 'Jules');

        $this->assertSame('Jules', $item->requested_by_name);
    }

    // -- syncLibrary -------------------------------------------------------

    public function test_sync_library_records_new_downloads_and_counts_repeats(): void
    {
        $token = PiToken::factory()->for($this->station)->create();
        $known = MediaAsset::factory()->create();
        $alreadyHad = MediaAsset::factory()->create();
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
