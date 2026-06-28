<?php

namespace Tests\Feature;

use App\Models\Song;
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
        Song::factory()->count(3)->create(['available' => true]);

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
        $song = Song::factory()->create(['available' => true]);

        // 5 requests allowed per minute
        for ($i = 0; $i < 5; $i++) {
            $this->post("/songs/{$song->id}/request", ['name' => 'Tester'])->assertRedirect();
        }

        $this->post("/songs/{$song->id}/request", ['name' => 'Tester'])->assertStatus(429);
    }

    public function test_frequency_is_shared_on_home_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('frequency'));
    }
}
