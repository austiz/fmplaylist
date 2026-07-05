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

        $response->assertSee('"server_url": "https://fmplaylist.test"', false);
        $response->assertSee('curl -fsSL https://fmplaylist.test/pi/setup.sh', false);
    }

    public function test_setup_script_writes_complete_daemon_config(): void
    {
        config(['app.url' => 'https://fmplaylist.com']);

        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('"server_url": "https://fmplaylist.com"', $script);
        $this->assertStringContainsString('"api_key": "$TOKEN"', $script);
        $this->assertStringContainsString('"song_dir": "$DIR"', $script);
        $this->assertStringContainsString('"commercial_dir": "$DIR/commercials"', $script);
        $this->assertStringContainsString('"sound_byte_dir": "$DIR/sound-bytes"', $script);
        $this->assertStringContainsString('"fallback_song": "FTPA.wav"', $script);
        $this->assertStringContainsString('"local_station_id_path": "$DIR/station_id.wav"', $script);
        $this->assertStringContainsString('"local_station_id_hash": ""', $script);
        $this->assertStringContainsString('"poll_interval_seconds": 5', $script);
        $this->assertStringContainsString('"verify_ssl": false', $script);
    }

    public function test_setup_script_detects_existing_copied_install_without_git_directory(): void
    {
        $script = $this->get('/pi/setup.sh')->assertOk()->getContent();

        $this->assertStringContainsString('[ ! -f "$DIR/pi_daemon.py" ] && [ ! -f "$DIR/pi_fm_rds" ]', $script);
        $this->assertStringContainsString('for file in pi_daemon.py run.sh wifi_setup.sh; do', $script);
    }
}
