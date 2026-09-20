<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The Pi source payload, as served to devices.
 *
 * This is the single source of truth for three things that MUST agree or the
 * "update available" banner lies: the file set the Pi downloads, the per-file
 * hashes it compares against, and the overall version hash the server shows.
 * They previously disagreed by construction — the Pi installed from GitHub main
 * while the server hashed its own deployed checkout — so any drift pinned the
 * update banner on permanently.
 */
class PiSource
{
    public const DIR = 'PiFmRds/src';

    /**
     * Payload files that are served but deliberately not in git.
     *
     * FTPA.wav is 48 MB of fallback audio that never changes. Tracking it made it
     * 92% of the repository, paid on every clone and every CI run, for a file git
     * cannot even delta-compress. It lives here instead, untracked, and is placed
     * on a server once -- see DEPLOY-NAMECHEAP.md. Anything dropped in this
     * directory is subject to the same allowlist as the source dir below.
     */
    public const ASSET_DIR = 'storage/app/pi';

    /**
     * What an install cannot proceed without.
     *
     * Kept in step with the required-file loop in public/pi/setup.sh. Its purpose
     * is to turn "the server is missing an untracked asset" into a statement the
     * operator can read, rather than an install that aborts on the device.
     *
     * @var list<string>
     */
    public const REQUIRED = ['pi_daemon.py', 'Makefile', 'pi_fm_rds.c', 'wifi_apply.sh', 'FTPA.wav'];

    /**
     * What may be sent to a device, by extension.
     *
     * An allowlist, not a denylist. This used to ship everything in the source dir,
     * which meant anything that ever landed there -- a dump, a backup, an editor's
     * stray save -- auto-deployed to every transmitter on the next update. The Pi
     * compiles PiFmRds itself (`make app` in the installer), so the C sources and
     * the Makefile are payload, not leftovers.
     */
    private const EXTENSIONS = ['c', 'h', 'py', 'sh', 'wav'];

    /** Files that are in the source dir but are not part of an install. */
    private const EXCLUDE = [
        // Per-device state, written by the installer.
        'config.json',
        // Tests. `make app` does not build rds_strings_test, and the daemon's own
        // suite runs in CI, not on a transmitter.
        'test_pi_daemon.py',
        'rds_strings_test.c',
        // Upstream PiFmRds's demo audio -- 3.6 MB that nothing in this project or in
        // the build references, sent over a Pi Zero W's wifi on every first install.
        'sound.wav',
        'sound_22050.wav',
        'stereo_44100.wav',
        'noise_22050.wav',
    ];

    /**
     * Files the Pi installs, name => absolute path, sorted by name.
     *
     * Directories and dotfiles are skipped: the Pi's own manifest is a flat list
     * of plain filenames. The device sees one flat payload, so the two source
     * directories are merged here -- DIR wins a name collision, because a file
     * dropped in the untracked asset dir must never shadow shipped code.
     *
     * @return array<string, string>
     */
    public static function files(): array
    {
        $found = self::scan(base_path(self::DIR)) + self::scan(base_path(self::ASSET_DIR));

        ksort($found);

        return $found;
    }

    /**
     * Allowlisted plain files directly inside $dir, name => absolute path.
     *
     * @return array<string, string>
     */
    private static function scan(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];

        foreach ((array) scandir($dir) as $name) {
            if (! is_string($name) || str_starts_with($name, '.')) {
                continue;
            }
            if (in_array($name, self::EXCLUDE, true)) {
                continue;
            }
            if ($name !== 'Makefile' && ! in_array(
                strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true
            )) {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) {
                $found[$name] = $path;
            }
        }

        return $found;
    }

    /**
     * Required payload files that are not on this server, sorted by name.
     *
     * Empty on a healthy install. Non-empty means someone deployed without
     * placing the untracked assets, and every device install will abort.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $missing = array_values(array_diff(self::REQUIRED, array_keys(self::files())));

        sort($missing);

        return $missing;
    }

    /** True when a filename is part of the payload — use before serving it. */
    public static function has(string $name): bool
    {
        return array_key_exists($name, self::files());
    }

    public static function path(string $name): ?string
    {
        return self::files()[$name] ?? null;
    }

    /**
     * Cheap fingerprint of the source dir: newest mtime, file count, total size.
     *
     * Folded into the cache keys so a deploy invalidates them immediately. With
     * a plain 5-minute TTL, the window right after a deploy served a manifest
     * of the OLD hashes while the files on disk were already new — every Pi
     * updating in that window failed checksum verification and aborted.
     */
    private static function fingerprint(): string
    {
        $newest = 0;
        $count = 0;
        $bytes = 0;

        foreach (self::files() as $path) {
            $newest = max($newest, (int) filemtime($path));
            $bytes += (int) filesize($path);
            $count++;
        }

        return "{$newest}-{$count}-{$bytes}";
    }

    /**
     * Per-file sha256 + size, for delta downloads.
     *
     * The Pi skips any file whose local hash already matches, so a code-only
     * change transfers ~70 KB instead of the full ~51 MB payload — which matters
     * on a Pi Zero W's wifi, where the full set takes many minutes.
     *
     * @return array<string, array{sha256: string, size: int}>
     */
    public static function manifest(): array
    {
        return Cache::remember('pi.source_manifest.'.self::fingerprint(), 300, function (): array {
            $manifest = [];

            foreach (self::files() as $name => $path) {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    continue;
                }

                $manifest[$name] = [
                    'sha256' => hash('sha256', $contents),
                    'size' => strlen($contents),
                ];
            }

            return $manifest;
        });
    }

    /**
     * Short version hash of the whole payload.
     *
     * Must stay byte-identical in shape to the daemon's _own_daemon_hash():
     * sha256 over a compact, key-sorted JSON map of name => sha256, first 12
     * chars. Change one side and every Pi reports as permanently out of date.
     */
    public static function hash(): string
    {
        return Cache::remember('pi.latest_source_hash.'.self::fingerprint(), 300, function (): string {
            $hashes = [];

            foreach (self::manifest() as $name => $meta) {
                $hashes[$name] = $meta['sha256'];
            }

            ksort($hashes);

            return substr(hash('sha256', (string) json_encode($hashes)), 0, 12);
        });
    }

    /**
     * Drop the cached manifest/hash for the current source state.
     *
     * Rarely needed — the fingerprint in the cache key means a changed source
     * dir already misses the old entry. Useful when file contents change
     * without altering mtime/size/count.
     */
    public static function forget(): void
    {
        $fingerprint = self::fingerprint();
        Cache::forget('pi.source_manifest.'.$fingerprint);
        Cache::forget('pi.latest_source_hash.'.$fingerprint);
    }
}
