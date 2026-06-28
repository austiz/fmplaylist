<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_settings_page_requires_auth(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
    }

    public function test_settings_page_loads(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/settings')->has('settings'));
    }

    public function test_frequency_can_be_updated(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings', [
                'frequency' => '101.5',
                'callsign' => 'Test FM',
                'fallback_song' => 'fallback.wav',
                'commercial_interval' => '4',
                'sound_byte_interval' => '2',
                'fade_in_duration' => '0.5',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('settings', ['key' => 'frequency', 'value' => '101.5']);
    }

    public function test_frequency_validation_rejects_out_of_range(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings', [
                'frequency' => '50.0',
                'callsign' => 'Test FM',
                'fallback_song' => 'fallback.wav',
                'commercial_interval' => '4',
                'sound_byte_interval' => '2',
                'fade_in_duration' => '0.5',
            ])
            ->assertSessionHasErrors('frequency');
    }
}
