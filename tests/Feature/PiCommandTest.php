<?php

namespace Tests\Feature;

use App\Models\PiCommand;
use App\Models\PiToken;
use App\Models\Station;
use App\Models\User;
use App\Support\PiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PiCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function heartbeat(string $raw, array $extra = []): TestResponse
    {
        return $this->postJson('/api/pi/heartbeat', [
            'status' => 'playing',
            'mode' => 'normal',
            ...$extra,
        ], ['X-Pi-Token' => $raw]);
    }

    public function test_queued_command_is_delivered_on_next_heartbeat(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');
        PiCommand::create(['pi_token_id' => $token->id, 'command' => 'reboot']);

        $response = $this->heartbeat($raw)->assertOk();

        $response->assertJsonPath('commands.0.command', 'reboot');
        $this->assertSame('sent', PiCommand::first()->status);
    }

    public function test_command_is_not_redelivered_after_being_sent(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');
        PiCommand::create(['pi_token_id' => $token->id, 'command' => 'reboot']);

        $this->heartbeat($raw)->assertOk();
        $this->heartbeat($raw)->assertOk()->assertJsonPath('commands', []);
    }

    public function test_each_device_receives_only_its_own_commands(): void
    {
        // The bug this replaces: station-scoped flags were cleared by whichever
        // Pi heartbeated first, so a second Pi on the same station never saw
        // the command at all.
        $station = Station::findOrFail(Station::defaultId());
        ['raw' => $rawA, 'token' => $a] = PiToken::generate('Pi A', $station->id);
        ['raw' => $rawB, 'token' => $b] = PiToken::generate('Pi B', $station->id);

        PiCommand::create(['pi_token_id' => $a->id, 'command' => 'reboot']);
        PiCommand::create(['pi_token_id' => $b->id, 'command' => 'restart_daemon']);

        $this->heartbeat($rawA)->assertOk()
            ->assertJsonPath('commands.0.command', 'reboot')
            ->assertJsonCount(1, 'commands');

        // A's heartbeat must not have consumed B's command.
        $this->heartbeat($rawB)->assertOk()
            ->assertJsonPath('commands.0.command', 'restart_daemon')
            ->assertJsonCount(1, 'commands');
    }

    public function test_device_can_ack_success(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');
        $cmd = PiCommand::create(['pi_token_id' => $token->id, 'command' => 'fetch_logs']);

        $this->heartbeat($raw)->assertOk();

        $this->postJson('/api/pi/ack-command', [
            'id' => $cmd->id,
            'ok' => true,
            'result' => 'journal output',
        ], ['X-Pi-Token' => $raw])->assertOk();

        $cmd->refresh();
        $this->assertSame('acked', $cmd->status);
        $this->assertSame('journal output', $cmd->result);
        $this->assertNotNull($cmd->completed_at);
    }

    public function test_device_can_ack_failure(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');
        $cmd = PiCommand::create(['pi_token_id' => $token->id, 'command' => 'rollback']);

        $this->postJson('/api/pi/ack-command', [
            'id' => $cmd->id,
            'ok' => false,
            'result' => 'no snapshot to roll back to',
        ], ['X-Pi-Token' => $raw])->assertOk();

        $cmd->refresh();
        $this->assertSame('failed', $cmd->status);
        $this->assertSame('no snapshot to roll back to', $cmd->result);
    }

    public function test_device_cannot_ack_another_devices_command(): void
    {
        ['token' => $a] = PiToken::generate('Pi A');
        ['raw' => $rawB] = PiToken::generate('Pi B');
        $cmd = PiCommand::create(['pi_token_id' => $a->id, 'command' => 'reboot']);

        $this->postJson('/api/pi/ack-command', [
            'id' => $cmd->id,
            'ok' => true,
        ], ['X-Pi-Token' => $rawB])->assertNotFound();

        $this->assertSame('queued', $cmd->fresh()->status);
    }

    public function test_stale_sent_commands_are_expired(): void
    {
        // A reboot takes the daemon down mid-command, so it never acks. Without
        // expiry the UI would show "in progress" forever.
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');
        $cmd = PiCommand::create([
            'pi_token_id' => $token->id,
            'command' => 'reboot',
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);

        $this->heartbeat($raw)->assertOk();

        $cmd->refresh();
        $this->assertSame('failed', $cmd->status);
        $this->assertSame('No acknowledgement from device', $cmd->result);
    }

    public function test_admin_can_queue_a_command(): void
    {
        ['token' => $token] = PiToken::generate('Pi A');

        $this->actingAs($this->admin)
            ->post("/admin/tokens/{$token->id}/command", ['command' => 'update'])
            ->assertRedirect();

        $this->assertDatabaseHas('pi_commands', [
            'pi_token_id' => $token->id,
            'command' => 'update',
            'status' => 'queued',
        ]);
    }

    public function test_unknown_command_is_rejected(): void
    {
        ['token' => $token] = PiToken::generate('Pi A');

        $this->actingAs($this->admin)
            ->post("/admin/tokens/{$token->id}/command", ['command' => 'rm_rf'])
            ->assertSessionHasErrors('command');

        $this->assertDatabaseCount('pi_commands', 0);
    }

    public function test_duplicate_in_flight_command_is_not_queued_twice(): void
    {
        ['token' => $token] = PiToken::generate('Pi A');

        $this->actingAs($this->admin)
            ->post("/admin/tokens/{$token->id}/command", ['command' => 'reboot']);
        $this->actingAs($this->admin)
            ->post("/admin/tokens/{$token->id}/command", ['command' => 'reboot']);

        // Otherwise an impatient admin stacks up reboots.
        $this->assertDatabaseCount('pi_commands', 1);
    }

    public function test_queueing_requires_auth(): void
    {
        ['token' => $token] = PiToken::generate('Pi A');

        $this->post("/admin/tokens/{$token->id}/command", ['command' => 'reboot'])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('pi_commands', 0);
    }

    public function test_heartbeat_persists_health_telemetry(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');

        // These were sent by the daemon and silently dropped by the validator.
        $this->heartbeat($raw, [
            'fm_running' => true,
            'ready_queue_depth' => 2,
            'last_error_message' => 'HTTP 401 — token rejected',
            'last_update_result' => 'rolled_back',
        ])->assertOk();

        $token->refresh();
        $this->assertTrue($token->pi_fm_running);
        $this->assertSame(2, $token->pi_queue_depth);
        $this->assertSame('HTTP 401 — token rejected', $token->pi_last_error);
        $this->assertSame('rolled_back', $token->pi_last_update_status);
    }

    public function test_up_to_date_device_reports_matching_hash(): void
    {
        ['raw' => $raw, 'token' => $token] = PiToken::generate('Pi A');

        $this->heartbeat($raw, ['daemon_hash' => PiSource::hash()])->assertOk();

        // The banner used to be permanently stuck on because the server hashed
        // its own checkout while the Pi installed from GitHub.
        $this->getJson('/api/pi-status')
            ->assertOk()
            ->assertJsonPath('update_available', false);
    }

    public function test_outdated_device_is_flagged(): void
    {
        ['raw' => $raw] = PiToken::generate('Pi A');

        $this->heartbeat($raw, ['daemon_hash' => 'deadbeef1234'])->assertOk();

        $this->getJson('/api/pi-status')
            ->assertOk()
            ->assertJsonPath('update_available', true);
    }
}
