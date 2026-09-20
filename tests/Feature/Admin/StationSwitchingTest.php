<?php

namespace Tests\Feature\Admin;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_defaults_to_the_seeded_default_station(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('activeStation.id', Station::defaultId()));
    }

    public function test_switching_station_persists_across_requests(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);

        $this->actingAs($this->admin)
            ->post('/admin/stations/switch', ['station_id' => $stationB->id])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertInertia(fn ($p) => $p->where('activeStation.id', $stationB->id));
    }

    public function test_settings_page_reflects_the_active_stations_data(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        Setting::set(SettingKey::Callsign, 'DEFAULT FM', Station::defaultId());
        Setting::set(SettingKey::Callsign, 'STATION B FM', $stationB->id);

        $this->actingAs($this->admin)->post('/admin/stations/switch', ['station_id' => $stationB->id]);

        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertInertia(fn ($p) => $p->where('settings.callsign', 'STATION B FM'));
    }

    public function test_redirects_to_station_creation_when_none_exist(): void
    {
        Station::query()->delete();

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertRedirect('/admin/stations');
    }
}
