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
     * Files the Pi installs, name => absolute path, sorted by name.
     *
     * Everything in the source dir except config.json, which is per-device state
     * written by the installer. Directories and dotfiles are skipped: the Pi's
     * own manifest is a flat list of plain filenames.
     *
     * @return array<string, string>
     */
    public static function files(): array
    {
        $dir = base_path(self::DIR);
        $found = [];

        foreach ((array) scandir($dir) as $name) {
            if (! is_string($name) || str_starts_with($name, '.')) {
                continue;
            }
            if ($name === 'config.json') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) {
                $found[$name] = $path;
            }
        }

        ksort($found);

        return $found;
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
