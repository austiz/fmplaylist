<?php

namespace Tests\Feature\Admin;

use App\Models\Commercial;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Setting;
use App\Models\Song;
use App\Models\SoundByte;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The seven broadcast write actions had no coverage at all, which made them the
 * riskiest thing to touch in the refactor: each one writes station settings or
 * device flags that the Pi acts on within 30 seconds. These characterize what
 * they do today, including that they all scope to the *active* station.
 */
class BroadcastControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->station = Station::findOrFail(Station::defaultId());
    }

    /** Switches the session's active station, as the admin station picker does. */
    private function switchTo(Station $station): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/stations/switch', ['station_id' => $station->id])
            ->assertRedirect();
    }

    // -- auth --------------------------------------------------------------

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function writeActions(): array
    {
        return [
            'mode' => ['/admin/broadcast/mode', ['broadcast_mode' => 'normal']],
            'rds' => ['/admin/broadcast/rds', ['rds_rt_mode' => 'auto']],
            'skip' => ['/admin/broadcast/skip', []],
            'play now' => ['/admin/broadcast/play-now', ['song_id' => 1]],
            'force commercial' => ['/admin/broadcast/force-commercial', ['commercial_id' => 1]],
            'force sound byte' => ['/admin/broadcast/force-sound-byte', ['sound_byte_id' => 1]],
            'emergency' => ['/admin/broadcast/emergency', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('writeActions')]
    public function test_write_actions_require_authentication(string $url, array $payload): void
    {
        $this->post($url, $payload)->assertRedirect('/login');
    }

    // -- index -------------------------------------------------------------

    public function test_the_page_lists_the_pickable_media_and_current_settings(): void
    {
        Song::factory()->create(['title' => 'Available Song']);
        Song::factory()->create(['title' => 'Missing Song', 'available' => false]);
        Commercial::factory()->create(['title' => 'Live Spot']);
        Commercial::factory()->create(['title' => 'Retired Spot', 'active' => false]);
        SoundByte::factory()->create(['title' => 'Drop']);
        Setting::set('broadcast_mode', 'usb_input', $this->station->id);

        $this->actingAs($this->admin)
            ->get('/admin/broadcast')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/broadcast')
                ->has('songs', 1)
                ->has('commercials', 1)
                ->has('soundBytes', 1)
                ->where('settings.broadcast_mode', 'usb_input'));
    }

    public function test_the_page_shows_only_the_active_stations_settings(): void
    {
        $other = Station::factory()->create();
        Setting::set('rds_ps', 'DEFAULT', $this->station->id);
        Setting::set('rds_ps', 'OTHER', $other->id);

        $this->switchTo($other);

        $this->actingAs($this->admin)
            ->get('/admin/broadcast')
            ->assertInertia(fn ($p) => $p->where('settings.rds_ps', 'OTHER'));
    }

    // -- setMode -----------------------------------------------------------

    public function test_setting_the_mode_stores_all_three_keys(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/mode', [
                'broadcast_mode' => 'custom_stream',
                'live_stream_url' => 'http://example.test/stream.mp3',
                'live_alsa_device' => 'hw:2,0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('custom_stream', Setting::get('broadcast_mode', null, $this->station->id));
        $this->assertSame('http://example.test/stream.mp3', Setting::get('live_stream_url', null, $this->station->id));
        $this->assertSame('hw:2,0', Setting::get('live_alsa_device', null, $this->station->id));
    }

    public function test_omitted_mode_fields_fall_back_to_their_defaults(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/mode', ['broadcast_mode' => 'normal'])
            ->assertRedirect();

        // Blank URL, but the ALSA device defaults to the capture card the Pi ships with.
        $this->assertSame('', Setting::get('live_stream_url', null, $this->station->id));
        $this->assertSame('hw:1,0', Setting::get('live_alsa_device', null, $this->station->id));
    }

    public function test_an_unknown_broadcast_mode_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/mode', ['broadcast_mode' => 'shortwave'])
            ->assertSessionHasErrors('broadcast_mode');

        $this->assertNull(Setting::get('broadcast_mode', null, $this->station->id));
    }

    public function test_the_mode_is_written_to_the_active_station_only(): void
    {
        $other = Station::factory()->create();
        $this->switchTo($other);

        $this->actingAs($this->admin)->post('/admin/broadcast/mode', ['broadcast_mode' => 'phone_stream']);

        $this->assertSame('phone_stream', Setting::get('broadcast_mode', null, $other->id));
        $this->assertNull(Setting::get('broadcast_mode', null, $this->station->id));
    }

    // -- updateRds ---------------------------------------------------------

    public function test_updating_rds_stores_the_mode_and_text(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/rds', [
                'rds_rt_mode' => 'custom',
                'rds_rt' => 'Now playing the good stuff',
                'rds_ps' => 'FMPLAY',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('custom', Setting::get('rds_rt_mode', null, $this->station->id));
        $this->assertSame('Now playing the good stuff', Setting::get('rds_rt', null, $this->station->id));
        $this->assertSame('FMPLAY', Setting::get('rds_ps', null, $this->station->id));
    }

    public function test_the_rds_program_service_name_is_capped_at_eight_characters(): void
    {
        // RDS PS is 8 characters by the standard, so anything longer would be
        // silently truncated by the transmitter.
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/rds', ['rds_rt_mode' => 'auto', 'rds_ps' => 'NINECHARS'])
            ->assertSessionHasErrors('rds_ps');
    }

    public function test_an_unknown_rds_mode_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/rds', ['rds_rt_mode' => 'manual'])
            ->assertSessionHasErrors('rds_rt_mode');
    }

    // -- skip --------------------------------------------------------------

    public function test_skipping_retires_the_playing_item_and_signals_every_device(): void
    {
        $playing = QueueItem::factory()->for($this->station)->playing()->create();
        $pending = QueueItem::factory()->for($this->station)->atPosition(1)->create();
        $tokens = PiToken::factory()->for($this->station)->count(2)->create();

        $this->actingAs($this->admin)
            ->post('/admin/broadcast/skip')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('skipped', $playing->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);

        // Both Pis on the station get the flag — unlike pi_emergency below.
        foreach ($tokens as $token) {
            $this->assertTrue((bool) $token->fresh()->pi_skip_next);
        }
    }

    public function test_skipping_leaves_other_stations_alone(): void
    {
        $other = Station::factory()->create();
        $otherPlaying = QueueItem::factory()->for($other)->playing()->create();
        $otherToken = PiToken::factory()->for($other)->create();

        $this->actingAs($this->admin)->post('/admin/broadcast/skip');

        $this->assertSame('playing', $otherPlaying->fresh()->status);
        $this->assertFalse((bool) $otherToken->fresh()->pi_skip_next);
    }

    // -- playNow -----------------------------------------------------------

    public function test_play_now_puts_the_song_at_the_front_of_the_queue(): void
    {
        $queued = QueueItem::factory()->for($this->station)->atPosition(1)->create();
        $song = Song::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/broadcast/play-now', ['song_id' => $song->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('queue_items', [
            'station_id' => $this->station->id,
            'song_id' => $song->id,
            'position' => 1,
            'status' => 'pending',
            'requested_by_name' => 'Admin',
        ]);
        $this->assertSame(2, $queued->fresh()->position);
    }

    public function test_play_now_requires_a_real_song(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/play-now', ['song_id' => 9999])
            ->assertSessionHasErrors('song_id');

        $this->assertSame(0, QueueItem::count());
    }

    public function test_play_now_queues_onto_the_active_station(): void
    {
        $other = Station::factory()->create();
        $song = Song::factory()->create();
        $this->switchTo($other);

        $this->actingAs($this->admin)->post('/admin/broadcast/play-now', ['song_id' => $song->id]);

        $this->assertDatabaseHas('queue_items', ['station_id' => $other->id, 'song_id' => $song->id]);
        $this->assertDatabaseMissing('queue_items', ['station_id' => $this->station->id]);
    }

    // -- forceCommercial / forceSoundByte ----------------------------------

    public function test_forcing_a_commercial_records_the_id_for_the_next_poll(): void
    {
        $commercial = Commercial::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/broadcast/force-commercial', ['commercial_id' => $commercial->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame((string) $commercial->id, Setting::get('force_commercial_id', null, $this->station->id));
    }

    public function test_forcing_a_commercial_requires_a_real_commercial(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/force-commercial', ['commercial_id' => 9999])
            ->assertSessionHasErrors('commercial_id');
    }

    public function test_forcing_a_sound_byte_records_the_id_for_the_next_poll(): void
    {
        $soundByte = SoundByte::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/broadcast/force-sound-byte', ['sound_byte_id' => $soundByte->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame((string) $soundByte->id, Setting::get('force_sound_byte_id', null, $this->station->id));
    }

    public function test_forcing_a_sound_byte_requires_a_real_sound_byte(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/broadcast/force-sound-byte', ['sound_byte_id' => 9999])
            ->assertSessionHasErrors('sound_byte_id');
    }

    // -- emergency ---------------------------------------------------------

    public function test_emergency_clears_the_queue_and_raises_the_flag(): void
    {
        $pending = QueueItem::factory()->for($this->station)->count(3)->create();
        $playing = QueueItem::factory()->for($this->station)->playing()->create();
        $token = PiToken::factory()->for($this->station)->create();
        Cache::forget("sse.queue_version.{$this->station->id}");

        $this->actingAs($this->admin)
            ->post('/admin/broadcast/emergency')
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ($pending as $item) {
            $this->assertSame('skipped', $item->fresh()->status);
            $this->assertNotNull($item->fresh()->played_at);
        }

        // Only pending rows are cleared; whatever is on air is stopped by the flag.
        $this->assertSame('playing', $playing->fresh()->status);
        $this->assertSame('1', Setting::get('pi_emergency', null, $this->station->id));
        $this->assertTrue((bool) $token->fresh()->pi_skip_next);
        $this->assertNotNull(Cache::get("sse.queue_version.{$this->station->id}"));
    }

    public function test_emergency_is_scoped_to_the_active_station(): void
    {
        $other = Station::factory()->create();
        $otherPending = QueueItem::factory()->for($other)->create();
        $this->switchTo($other);

        $this->actingAs($this->admin)->post('/admin/broadcast/emergency');

        $this->assertSame('skipped', $otherPending->fresh()->status);
        $this->assertSame('1', Setting::get('pi_emergency', null, $other->id));
        $this->assertNull(Setting::get('pi_emergency', null, $this->station->id));
    }
}
