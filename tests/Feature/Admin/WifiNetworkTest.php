<?php

namespace Tests\Feature\Admin;

use App\Models\PiToken;
use App\Models\Station;
use App\Models\User;
use App\Models\WifiNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WifiNetworkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_saving_a_network_requires_auth(): void
    {
        $this->post('/admin/settings/wifi/networks', ['ssid' => 'Cafe'])
            ->assertRedirect('/login');
    }

    public function test_network_can_be_saved_with_free_text_ssid(): void
    {
        // The whole point: a fallback network is out of range when configured,
        // so it can never come from the Pi's scan list.
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', [
                'ssid' => 'Not In Range AP',
                'password' => 'hunter2hunter',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('wifi_networks', [
            'ssid' => 'Not In Range AP',
            'station_id' => Station::defaultId(),
        ]);
    }

    public function test_open_network_saves_without_password(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', ['ssid' => 'Free WiFi']);

        $net = WifiNetwork::where('ssid', 'Free WiFi')->firstOrFail();
        $this->assertNull($net->password);
    }

    public function test_short_password_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', [
                'ssid' => 'Cafe',
                'password' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('wifi_networks', 0);
    }

    public function test_resaving_same_ssid_updates_instead_of_duplicating(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', ['ssid' => 'Cafe', 'password' => 'oldpassword']);
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', ['ssid' => 'Cafe', 'password' => 'newpassword']);

        $this->assertDatabaseCount('wifi_networks', 1);
        $this->assertSame('newpassword', WifiNetwork::where('ssid', 'Cafe')->value('password'));
    }

    public function test_networks_are_appended_in_order(): void
    {
        foreach (['First', 'Second', 'Third'] as $ssid) {
            $this->actingAs($this->admin)
                ->post('/admin/settings/wifi/networks', ['ssid' => $ssid]);
        }

        $this->assertSame(
            ['First', 'Second', 'Third'],
            WifiNetwork::ordered(Station::defaultId())->pluck('ssid')->all()
        );
    }

    public function test_networks_can_be_reordered(): void
    {
        foreach (['First', 'Second', 'Third'] as $ssid) {
            $this->actingAs($this->admin)
                ->post('/admin/settings/wifi/networks', ['ssid' => $ssid]);
        }

        $ids = WifiNetwork::ordered(Station::defaultId())->pluck('id')->all();

        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks/reorder', [
                'ids' => [$ids[2], $ids[0], $ids[1]],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['Third', 'First', 'Second'],
            WifiNetwork::ordered(Station::defaultId())->pluck('ssid')->all()
        );
    }

    public function test_network_can_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', ['ssid' => 'Cafe']);
        $net = WifiNetwork::where('ssid', 'Cafe')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete("/admin/settings/wifi/networks/{$net->id}")
            ->assertRedirect();

        $this->assertDatabaseCount('wifi_networks', 0);
    }

    public function test_cannot_delete_another_stations_network(): void
    {
        $other = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $net = WifiNetwork::create([
            'station_id' => $other->id,
            'ssid' => 'Theirs',
            'priority' => 0,
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/settings/wifi/networks/{$net->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('wifi_networks', 1);
    }

    public function test_settings_page_exposes_saved_list_without_passwords(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks', ['ssid' => 'Cafe', 'password' => 'supersecret']);

        $response = $this->actingAs($this->admin)->get('/admin/settings')->assertOk();

        $response->assertInertia(fn ($p) => $p
            ->component('admin/settings')
            ->has('wifi.saved', 1)
            ->where('wifi.saved.0.ssid', 'Cafe')
            ->where('wifi.saved.0.has_password', true)
        );

        // Passwords must never reach the browser.
        $response->assertDontSee('supersecret');
    }

    public function test_pi_receives_ordered_profiles_and_stable_revision(): void
    {
        foreach (['Primary', 'Backup'] as $ssid) {
            $this->actingAs($this->admin)
                ->post('/admin/settings/wifi/networks', ['ssid' => $ssid, 'password' => 'passphrase1']);
        }

        ['raw' => $raw] = PiToken::generate('Test Pi');

        $response = $this->postJson('/api/pi/heartbeat', [
            'status' => 'playing',
            'mode' => 'normal',
        ], ['X-Pi-Token' => $raw])->assertOk();

        $response->assertJsonPath('wifi_profiles.0.ssid', 'Primary');
        $response->assertJsonPath('wifi_profiles.1.ssid', 'Backup');
        // The Pi needs the PSK to actually join the network.
        $response->assertJsonPath('wifi_profiles.0.password', 'passphrase1');

        $rev = $response->json('wifi_profiles_rev');
        $this->assertNotSame('', $rev);

        // Stable across calls, or the Pi would re-run nmcli on every heartbeat.
        $this->assertSame(
            $rev,
            WifiNetwork::revisionFor(Station::defaultId())
        );
    }

    public function test_revision_changes_when_order_changes(): void
    {
        foreach (['A', 'B'] as $ssid) {
            $this->actingAs($this->admin)
                ->post('/admin/settings/wifi/networks', ['ssid' => $ssid]);
        }

        $before = WifiNetwork::revisionFor(Station::defaultId());
        $ids = WifiNetwork::ordered(Station::defaultId())->pluck('id')->all();

        $this->actingAs($this->admin)
            ->post('/admin/settings/wifi/networks/reorder', ['ids' => [$ids[1], $ids[0]]]);

        $this->assertNotSame($before, WifiNetwork::revisionFor(Station::defaultId()));
    }

    public function test_revision_is_empty_with_no_saved_networks(): void
    {
        $this->assertSame('', WifiNetwork::revisionFor(Station::defaultId()));
    }
}
