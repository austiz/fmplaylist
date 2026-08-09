<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiSetupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_script_uses_request_host_when_app_url_is_blank(): void
    {
        config(['app.url' => '']);

        $response = $this->get('https://fmplaylist.test/pi/setup.sh')->assertOk();

        $response->assertSee('BASE_URL="https://fmplaylist.test"', false);
        $response->assertSee('curl -fsSL https://fmplaylist.test/pi/setup.sh', false);
    }

    public function test_setup_script_writes_complete_daemon_config(): void
    {
        config(['app.url' => 'https://fmplaylist.com']);

        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('BASE_URL="https://fmplaylist.com"', $script);
        $this->assertStringContainsString('python3 - "$DIR/config.json" "$BASE_URL" "$TOKEN" "$DIR"', $script);
        $this->assertStringContainsString("'server_url': base_url", $script);
        $this->assertStringContainsString("'api_key': token", $script);
        $this->assertStringContainsString("'song_dir': install_dir", $script);
        $this->assertStringContainsString("'commercial_dir': os.path.join(install_dir, 'commercials')", $script);
        $this->assertStringContainsString("'sound_byte_dir': os.path.join(install_dir, 'sound-bytes')", $script);
        $this->assertStringContainsString("'fallback_song': 'FTPA.wav'", $script);
        $this->assertStringContainsString("'local_station_id_path': os.path.join(install_dir, 'station_id.wav')", $script);
        $this->assertStringContainsString("'local_station_id_hash': keep('local_station_id_hash', '')", $script);
        $this->assertStringContainsString("'poll_interval_seconds': keep('poll_interval_seconds', 5)", $script);
        $this->assertStringContainsString("'verify_ssl': keep('verify_ssl', False)", $script);
    }

    public function test_setup_script_fetches_payload_from_this_server_not_github(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        // The Pi must install what this server reports as current. Cloning
        // GitHub meant the server's hash described a different tree, so
        // "update available" could never clear.
        $this->assertStringNotContainsString('git clone', $script);
        $this->assertStringContainsString('$BASE_URL/pi/manifest.json', $script);
        $this->assertStringContainsString('$BASE_URL/pi/$name', $script);

        // Every downloaded file is checksummed against the manifest.
        $this->assertStringContainsString('checksum mismatch for $name', $script);

        $this->assertStringContainsString('SOURCE_MANIFEST_FILE="$STATE_DIR/source-files.txt"', $script);
        $this->assertStringContainsString('rm -f "$DIR/$old_file"', $script);
    }

    public function test_setup_script_never_destroys_a_working_binary(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        // `make clean && make app` in the live tree deleted pi_fm_rds before
        // rebuilding it, so a failed build left the Pi unable to transmit.
        $this->assertStringNotContainsString('cd "$DIR" && make clean', $script);
        $this->assertStringContainsString('cd "$SRC" && make app', $script);
        $this->assertStringContainsString('The existing install is untouched and still running.', $script);
    }

    public function test_setup_script_detaches_the_restart_and_can_roll_back(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        // A plain `systemctl restart` killed the installer during a self-update,
        // because the installer runs inside the unit being restarted.
        $this->assertStringContainsString('systemd-run --unit=fmplaylist-postinstall', $script);
        $this->assertStringContainsString('--postinstall', $script);
        $this->assertStringContainsString('rollback_install', $script);
        $this->assertStringContainsString('run_health_check', $script);
        $this->assertStringContainsString('LAST_GOOD_DIR="$PI_DIR/.last-good"', $script);
    }

    public function test_setup_script_preserves_admin_set_frequency(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        // Was hardcoded to 96.9, silently resetting every updated Pi.
        $this->assertStringContainsString("'freq': keep('freq', 96.9)", $script);
    }

    public function test_setup_script_preserves_runtime_state_and_rewrites_config(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('mkdir -p "$DIR" "$STATE_DIR" "$DIR/commercials" "$DIR/sound-bytes"', $script);
        $this->assertStringContainsString('old = json.load(f)', $script);
        $this->assertStringContainsString("'pi_code': keep('pi_code', 'C0DE')", $script);
        $this->assertStringContainsString("'callsign': keep('callsign', '96.9 FM ')", $script);
        $this->assertStringContainsString("'local_station_id_hash': keep('local_station_id_hash', '')", $script);
        $this->assertStringContainsString("'poll_interval_seconds': keep('poll_interval_seconds', 5)", $script);
        $this->assertStringContainsString("'verify_ssl': keep('verify_ssl', False)", $script);
    }

    public function test_setup_script_rebuilds_when_native_checksum_changes(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('NATIVE_SHA_FILE="$STATE_DIR/native.sha256"', $script);
        $this->assertStringContainsString('native_checksum()', $script);
        $this->assertStringContainsString('FMPLAYLIST_FORCE_REBUILD="${FMPLAYLIST_FORCE_REBUILD:-0}"', $script);
        $this->assertStringContainsString('elif [ "$NEW_NATIVE_SHA" != "$OLD_NATIVE_SHA" ]; then', $script);
        $this->assertStringContainsString('echo "$NEW_NATIVE_SHA" > "$NATIVE_SHA_FILE"', $script);
    }
}
