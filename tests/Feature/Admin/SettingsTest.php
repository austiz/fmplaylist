<?php

namespace Tests\Feature\Admin;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\Station;
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

    public function test_settings_are_isolated_per_station(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);

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

        // Default station's frequency was updated...
        $this->assertSame(101.5, Setting::get(SettingKey::Frequency, Station::defaultId()));
        // ...but station B, which was never the active session station, is untouched.
        $this->assertSame(SettingKey::Frequency->default(), Setting::get(SettingKey::Frequency, $stationB->id));
    }
}
