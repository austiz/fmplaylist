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

    public function test_setup_script_refreshes_full_source_snapshot(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('FMPLAYLIST_REPO="${FMPLAYLIST_REPO:-https://github.com/austiz/fmplaylist.git}"', $script);
        $this->assertStringContainsString('FMPLAYLIST_REF="${FMPLAYLIST_REF:-main}"', $script);
        $this->assertStringContainsString('git clone --depth 1 --branch "$FMPLAYLIST_REF" "$FMPLAYLIST_REPO"', $script);
        $this->assertStringContainsString('SOURCE_MANIFEST_FILE="$STATE_DIR/source-files.txt"', $script);
        $this->assertStringContainsString('find "$SRC" -maxdepth 1 -type f ! -name \'config.json\' -printf \'%f\n\' | sort > "$NEW_SOURCE_MANIFEST"', $script);
        $this->assertStringContainsString('rm -f "$DIR/$old_file"', $script);
        $this->assertStringContainsString('find "$SRC" -maxdepth 1 -type f ! -name \'config.json\' -exec cp -f {} "$DIR/"', $script);
        $this->assertStringContainsString('cp "$NEW_SOURCE_MANIFEST" "$SOURCE_MANIFEST_FILE"', $script);
        $this->assertStringNotContainsString('for file in pi_daemon.py run.sh wifi_setup.sh; do', $script);
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
        $this->assertStringContainsString('cd "$DIR" && make clean && make app', $script);
        $this->assertStringContainsString('echo "$NEW_NATIVE_SHA" > "$NATIVE_SHA_FILE"', $script);
    }
}
