<?php

namespace Tests\Feature\Admin;

use App\Enums\MediaType;
use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\PiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SoundsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_sounds_page_requires_auth(): void
    {
        $this->get('/admin/sounds')->assertRedirect('/login');
    }

    public function test_sounds_page_loads_with_all_media_types(): void
    {
        MediaAsset::factory()->create(['title' => 'Test Song', 'active' => true]);
        MediaAsset::factory()->commercial()->create(['title' => 'Test Commercial', 'active' => true]);
        MediaAsset::factory()->soundByte()->create(['title' => 'Test Drop', 'category' => 'drop', 'active' => true]);

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/sounds')
                ->has('songs.data', 1)
                ->has('commercials', 1)
                ->has('soundBytes', 1));
    }

    /** Each tab must show only its own type, now that all three share a table. */
    public function test_sounds_page_does_not_mix_the_types(): void
    {
        MediaAsset::factory()->commercial()->create(['title' => 'Spot']);
        MediaAsset::factory()->soundByte()->create(['title' => 'Drop']);

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('songs.data', 0)
                ->has('commercials', 1)
                ->has('soundBytes', 1)
                ->where('commercials.0.title', 'Spot')
                ->where('soundBytes.0.title', 'Drop'));
    }

    /**
     * One resource serializes all three, so the shared keys must be spelled the
     * same for all three. Songs used to report `available` for the very same
     * column the other two called `active`.
     */
    public function test_every_type_reports_the_same_shared_keys(): void
    {
        $shared = ['id', 'title', 'filename', 'file_size', 'active', 'has_file', 'devices_have', 'pi_delete_requested'];

        MediaAsset::factory()->create();
        MediaAsset::factory()->commercial()->create();
        MediaAsset::factory()->soundByte()->create();

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('songs.data.0', fn ($row) => $row->hasAll($shared)->etc())
                ->has('commercials.0', fn ($row) => $row->hasAll($shared)->etc())
                ->has('soundBytes.0', fn ($row) => $row->hasAll($shared)->etc()));
    }

    public function test_song_search_does_not_leak_other_types(): void
    {
        MediaAsset::factory()->create(['title' => 'Shared Name']);
        MediaAsset::factory()->commercial()->create(['title' => 'Shared Name']);

        $this->actingAs($this->admin)
            ->get('/admin/sounds?search=Shared')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('songs.data', 1));
    }

    public function test_song_can_be_toggled(): void
    {
        $song = MediaAsset::factory()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/songs/{$song->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($song->fresh()->active);
    }

    /**
     * Ids are unique across the whole library now, so `/admin/songs/{id}` would happily
     * edit a commercial if the controller did not check the type.
     */
    public function test_song_routes_404_for_a_commercial_id(): void
    {
        $commercial = MediaAsset::factory()->commercial()->create();

        $this->actingAs($this->admin)
            ->patch("/admin/songs/{$commercial->id}/toggle")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete("/admin/songs/{$commercial->id}")
            ->assertNotFound();
    }

    public function test_song_no_device_holds_is_deleted_outright(): void
    {
        $song = MediaAsset::factory()->create(['storage_path' => null, 'needs_pi_download' => false]);

        $this->actingAs($this->admin)
            ->delete("/admin/songs/{$song->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('media_assets', ['id' => $song->id]);
    }

    public function test_song_held_by_a_device_is_marked_for_deletion(): void
    {
        ['token' => $token] = PiToken::generate('Test Pi');
        $token->update(['last_seen_at' => now()]);

        $song = MediaAsset::factory()->create(['storage_path' => 'songs/a.mp3']);
        DeviceDownload::create([
            'pi_token_id' => $token->id,
            'media_type' => 'song',
            'media_id' => $song->id,
            'downloaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/songs/{$song->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('media_assets', ['id' => $song->id]);
        $this->assertTrue((bool) $song->fresh()->pi_delete_requested);
    }

    public function test_song_can_be_updated(): void
    {
        $song = MediaAsset::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin)
            ->patch("/admin/songs/{$song->id}", ['title' => 'New Title', 'artist' => 'New Artist'])
            ->assertRedirect();

        $this->assertDatabaseHas('media_assets', ['id' => $song->id, 'title' => 'New Title', 'artist' => 'New Artist']);
    }

    public function test_commercial_can_be_toggled(): void
    {
        $commercial = MediaAsset::factory()->commercial()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/commercials/{$commercial->id}/toggle")
            ->assertRedirect();

        $this->assertFalse((bool) $commercial->fresh()->active);
    }

    public function test_commercial_upload_uses_slug_safe_filename(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/admin/commercials/upload', [
                'title' => 'Wild Sale / 50% Off!',
                'file' => UploadedFile::fake()->create('spot.wav', 100, 'audio/wav'),
            ])
            ->assertRedirect();

        $commercial = MediaAsset::query()->ofType(MediaType::Commercial)->firstOrFail();
        $this->assertMatchesRegularExpression('/^wild-sale-50-off_\d+\.wav$/', $commercial->filename);
    }

    public function test_commercial_update_requires_a_rotation_order(): void
    {
        $commercial = MediaAsset::factory()->commercial()->create();

        $this->actingAs($this->admin)
            ->patch("/admin/commercials/{$commercial->id}", ['title' => 'Renamed'])
            ->assertSessionHasErrors('rotation_order');
    }

    public function test_sound_byte_can_be_toggled(): void
    {
        $sb = MediaAsset::factory()->soundByte()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/sound-bytes/{$sb->id}/toggle")
            ->assertRedirect();

        $this->assertFalse((bool) $sb->fresh()->active);
    }

    public function test_sound_byte_upload_rejects_an_unknown_category(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/admin/sound-bytes/upload', [
                'title' => 'Mystery',
                'category' => 'not-a-category',
                'file' => UploadedFile::fake()->create('drop.wav', 100, 'audio/wav'),
            ])
            ->assertSessionHasErrors('category');
    }

    // ── "On Pi" reporting ─────────────────────────────────────────────────────
    //
    // Sync state must come from device_downloads. `needs_pi_download` is global and
    // defaults to false, which made untouched media report as already on the Pi.

    public function test_media_no_device_downloaded_is_not_reported_as_on_pi(): void
    {
        ['token' => $token] = PiToken::generate('Test Pi');
        $token->update(['last_seen_at' => now()]);

        MediaAsset::factory()->create(['needs_pi_download' => false, 'storage_path' => 'songs/a.mp3']);
        MediaAsset::factory()->commercial()->create(['needs_pi_download' => false]);
        MediaAsset::factory()->soundByte()->create(['needs_pi_download' => false]);

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('deviceCount', 1)
                ->where('songs.data.0.devices_have', 0)
                ->where('commercials.0.devices_have', 0)
                ->where('soundBytes.0.devices_have', 0));
    }

    public function test_media_reports_only_the_devices_that_downloaded_it(): void
    {
        ['token' => $piA] = PiToken::generate('Pi A');
        ['token' => $piB] = PiToken::generate('Pi B');
        $piA->update(['last_seen_at' => now()]);
        $piB->update(['last_seen_at' => now()]);

        $soundByte = MediaAsset::factory()->soundByte()->create(['storage_path' => 'soundbytes/a.mp3']);
        DeviceDownload::create([
            'pi_token_id' => $piA->id,
            'media_type' => 'sound_byte',
            'media_id' => $soundByte->id,
            'downloaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('deviceCount', 2)
                ->where('soundBytes.0.devices_have', 1));
    }

    public function test_device_count_ignores_tokens_that_never_checked_in(): void
    {
        PiToken::generate('Never provisioned');

        MediaAsset::factory()->soundByte()->create();

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('deviceCount', 0));
    }

    public function test_sound_byte_held_by_a_device_is_marked_for_deletion(): void
    {
        ['token' => $token] = PiToken::generate('Test Pi');
        $token->update(['last_seen_at' => now()]);

        // Flag says "no Pi has it" but a device_downloads row proves otherwise.
        $soundByte = MediaAsset::factory()->soundByte()->create(['needs_pi_download' => true]);
        DeviceDownload::create([
            'pi_token_id' => $token->id,
            'media_type' => 'sound_byte',
            'media_id' => $soundByte->id,
            'downloaded_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/sound-bytes/{$soundByte->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('media_assets', ['id' => $soundByte->id]);
        $this->assertTrue((bool) $soundByte->fresh()->pi_delete_requested);
    }

    public function test_sound_byte_no_device_holds_is_deleted_immediately(): void
    {
        $soundByte = MediaAsset::factory()->soundByte()->create(['needs_pi_download' => false]);

        $this->actingAs($this->admin)
            ->delete("/admin/sound-bytes/{$soundByte->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('media_assets', ['id' => $soundByte->id]);
    }

    public function test_sound_byte_upload_uses_slug_safe_filename(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)
            ->post('/admin/sound-bytes/upload', [
                'title' => 'DJ Drop / Wow!',
                'category' => 'drop',
                'file' => UploadedFile::fake()->create('drop.wav', 100, 'audio/wav'),
            ])
            ->assertRedirect();

        $soundByte = MediaAsset::query()->ofType(MediaType::SoundByte)->firstOrFail();
        $this->assertMatchesRegularExpression('/^dj-drop-wow_\d+\.wav$/', $soundByte->filename);
    }
}
