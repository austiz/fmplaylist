<?php

namespace Tests\Feature;

use App\Support\PiSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PiSourceTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function tearDown(): void
    {
        if (isset($this->scratch) && is_file($this->scratch)) {
            unlink($this->scratch);
        }

        parent::tearDown();
    }

    private function scratchPath(string $name): string
    {
        return base_path(PiSource::DIR.'/'.$name);
    }

    public function test_manifest_covers_the_payload_and_excludes_config(): void
    {
        $manifest = PiSource::manifest();

        $this->assertArrayHasKey('pi_daemon.py', $manifest);
        $this->assertArrayHasKey('wifi_apply.sh', $manifest);
        // The installer runs `make app` on the device, so the build inputs ship too.
        $this->assertArrayHasKey('Makefile', $manifest);
        $this->assertArrayHasKey('pi_fm_rds.c', $manifest);
        // config.json is per-device state written by the installer, never shipped.
        $this->assertArrayNotHasKey('config.json', $manifest);

        foreach ($manifest as $meta) {
            $this->assertSame(64, strlen($meta['sha256']));
        }
    }

    public function test_tests_and_unused_demo_audio_are_not_shipped_to_devices(): void
    {
        $manifest = PiSource::manifest();

        // Nothing on a transmitter runs these, and the demo wavs are 3.6 MB of
        // upstream sample audio that nothing in this project references.
        foreach (['test_pi_daemon.py', 'rds_strings_test.c', 'sound.wav', 'stereo_44100.wav'] as $name) {
            $this->assertArrayNotHasKey($name, $manifest);
            $this->assertNull(PiSource::path($name), "{$name} is still downloadable");
        }
    }

    public function test_a_stray_file_in_the_source_dir_is_not_auto_deployed(): void
    {
        // The payload was everything in the directory, so anything that ever landed
        // there -- a dump, a backup, an editor's stray save -- went out to every
        // transmitter on the next update.
        $this->scratch = $this->scratchPath('database_backup.sql');
        file_put_contents($this->scratch, '-- secrets
');

        $this->assertArrayNotHasKey('database_backup.sql', PiSource::manifest());
        $this->get('/pi/database_backup.sql')->assertNotFound();
    }

    public function test_served_files_match_their_manifest_hashes(): void
    {
        // A mismatch here aborts the install on the Pi with a checksum error.
        foreach (PiSource::manifest() as $name => $meta) {
            $path = PiSource::path($name);
            $this->assertNotNull($path, "manifest lists {$name} but it cannot be served");
            $this->assertSame(
                $meta['sha256'],
                hash_file('sha256', $path),
                "served bytes for {$name} do not match the manifest"
            );
        }
    }

    public function test_manifest_updates_immediately_when_source_changes(): void
    {
        // A plain TTL cache served the OLD hashes for up to 5 minutes after a
        // deploy while the files on disk were already new, so every Pi updating
        // in that window failed checksum verification and aborted.
        $before = PiSource::hash();

        $this->scratch = $this->scratchPath('zz_deploy_probe.py');
        file_put_contents($this->scratch, "# added by deploy\n");

        $after = PiSource::hash();
        $this->assertNotSame($before, $after, 'hash did not react to a new source file');

        $manifest = PiSource::manifest();
        $this->assertArrayHasKey('zz_deploy_probe.py', $manifest);
        $this->assertSame(
            hash_file('sha256', $this->scratch),
            $manifest['zz_deploy_probe.py']['sha256']
        );

        unlink($this->scratch);
        $this->assertSame($before, PiSource::hash(), 'hash did not return to its original value');
    }

    public function test_hash_matches_the_daemons_own_algorithm(): void
    {
        // pi_daemon._own_daemon_hash(): sha256 over a compact, key-sorted JSON
        // map of name => sha256, truncated to 12 chars. If the two ever drift,
        // every Pi reports as permanently out of date.
        $hashes = [];
        foreach (PiSource::manifest() as $name => $meta) {
            $hashes[$name] = $meta['sha256'];
        }
        ksort($hashes);

        $expected = substr(
            hash('sha256', json_encode($hashes, JSON_UNESCAPED_SLASHES)),
            0,
            12
        );

        $this->assertSame($expected, PiSource::hash());
    }

    public function test_manifest_endpoint_serves_hash_and_files(): void
    {
        $this->getJson('/pi/manifest.json')
            ->assertOk()
            ->assertJsonPath('hash', PiSource::hash())
            ->assertJsonStructure(['hash', 'files' => ['pi_daemon.py' => ['sha256', 'size']]]);
    }

    public function test_only_payload_files_can_be_downloaded(): void
    {
        $this->get('/pi/pi_daemon.py')->assertOk();

        // config.json holds this device's API token.
        $this->get('/pi/config.json')->assertNotFound();
    }

    public function test_the_untracked_asset_dir_is_part_of_one_flat_payload(): void
    {
        // FTPA.wav is 48 MB and is not in the repository, so it cannot live beside
        // the sources. The device still sees a single flat file list, so the two
        // directories have to merge into one manifest -- and the allowlist has to
        // apply to the asset dir too, or it becomes a new way to auto-deploy a
        // stray file to every transmitter.
        $this->scratch = base_path(PiSource::ASSET_DIR.'/zz_asset_probe.wav');
        file_put_contents($this->scratch, 'RIFF');

        $manifest = PiSource::manifest();
        $this->assertArrayHasKey('zz_asset_probe.wav', $manifest);
        $this->assertSame(realpath($this->scratch), realpath((string) PiSource::path('zz_asset_probe.wav')));
        $this->get('/pi/zz_asset_probe.wav')->assertOk();

        $stray = base_path(PiSource::ASSET_DIR.'/zz_asset_probe.sql');
        file_put_contents($stray, '-- secrets');
        try {
            $this->assertArrayNotHasKey('zz_asset_probe.sql', PiSource::manifest());
            $this->get('/pi/zz_asset_probe.sql')->assertNotFound();
        } finally {
            unlink($stray);
        }
    }

    public function test_the_source_dir_wins_a_name_collision(): void
    {
        // The asset dir is untracked and, on a server, writable. A file dropped
        // there must not be able to shadow shipped code.
        $this->scratch = base_path(PiSource::ASSET_DIR.'/pi_daemon.py');
        file_put_contents($this->scratch, '# impostor');

        $this->assertSame(
            realpath(base_path(PiSource::DIR.'/pi_daemon.py')),
            realpath((string) PiSource::path('pi_daemon.py'))
        );
    }

    public function test_a_server_missing_a_required_file_says_so_in_the_manifest(): void
    {
        // The alternative is a payload that looks healthy here and aborts on the
        // device, mid-install, for reasons the operator cannot see from the Pi.
        $this->assertContains('FTPA.wav', PiSource::REQUIRED);

        $real = base_path(PiSource::DIR.'/wifi_apply.sh');
        $aside = $real.'.moved-by-test';
        rename($real, $aside);

        try {
            $this->assertSame(['wifi_apply.sh'], array_values(array_diff(
                PiSource::missing(), ['FTPA.wav']
            )));

            $this->getJson('/pi/manifest.json')
                ->assertOk()
                ->assertJsonFragment(['missing' => PiSource::missing()]);
        } finally {
            rename($aside, $real);
        }

        $this->assertNotContains('wifi_apply.sh', PiSource::missing());
    }
}
