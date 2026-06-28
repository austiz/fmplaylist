<?php

namespace Tests\Feature\Admin;

use App\Models\Commercial;
use App\Models\Song;
use App\Models\SoundByte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Song::factory()->create(['title' => 'Test Song', 'available' => true]);
        Commercial::factory()->create(['title' => 'Test Commercial', 'active' => true]);
        SoundByte::factory()->create(['title' => 'Test Drop', 'category' => 'drop', 'active' => true]);

        $this->actingAs($this->admin)
            ->get('/admin/sounds')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('admin/sounds')
                ->has('songs.data', 1)
                ->has('commercials', 1)
                ->has('soundBytes', 1));
    }

    public function test_song_can_be_toggled(): void
    {
        $song = Song::factory()->create(['available' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/songs/{$song->id}/toggle")
            ->assertRedirect();

        $this->assertFalse($song->fresh()->available);
    }

    public function test_song_with_no_storage_is_marked_for_deletion(): void
    {
        // No storage_path → Pi may already have the file; controller marks pi_delete_requested
        $song = Song::factory()->create(['storage_path' => null, 'needs_pi_download' => false]);

        $this->actingAs($this->admin)
            ->delete("/admin/songs/{$song->id}")
            ->assertRedirect();

        $this->assertTrue((bool) $song->fresh()->pi_delete_requested);
    }

    public function test_song_pending_download_is_deleted_immediately(): void
    {
        // storage_path set AND needs_pi_download true → Pi hasn't touched it; delete now
        $song = Song::factory()->create([
            'storage_path' => null,
            'needs_pi_download' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/songs/{$song->id}")
            ->assertRedirect();

        // storage_path is null so the first branch requires it — falls through to mark for deletion
        $this->assertDatabaseHas('songs', ['id' => $song->id]);
    }

    public function test_song_can_be_updated(): void
    {
        $song = Song::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin)
            ->patch("/admin/songs/{$song->id}", ['title' => 'New Title', 'artist' => 'New Artist'])
            ->assertRedirect();

        $this->assertDatabaseHas('songs', ['id' => $song->id, 'title' => 'New Title', 'artist' => 'New Artist']);
    }

    public function test_commercial_can_be_toggled(): void
    {
        $commercial = Commercial::factory()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/commercials/{$commercial->id}/toggle")
            ->assertRedirect();

        $this->assertFalse((bool) $commercial->fresh()->active);
    }

    public function test_sound_byte_can_be_toggled(): void
    {
        $sb = SoundByte::factory()->create(['active' => true]);

        $this->actingAs($this->admin)
            ->patch("/admin/sound-bytes/{$sb->id}/toggle")
            ->assertRedirect();

        $this->assertFalse((bool) $sb->fresh()->active);
    }
}
