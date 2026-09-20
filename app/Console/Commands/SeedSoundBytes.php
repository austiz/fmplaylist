<?php

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-imports a directory of generated voice clips as sound bytes.
 *
 * Titles are derived from ElevenLabs' export filenames, which carry a timestamp
 * prefix and a generation-parameter suffix around the voice name.
 */
class SeedSoundBytes extends Command
{
    protected $signature = 'soundbytes:seed
        {--dir= : Directory to import audio files from}
        {--category=id : Sound byte category (jingle, shoutout, drop, id)}';

    protected $description = 'Import a directory of MP3/WAV files as sound bytes';

    public function handle(): int
    {
        $dir = (string) $this->option('dir');

        if ($dir === '') {
            $this->error('Pass --dir=/path/to/audio.');

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            $this->error("Not a directory: {$dir}");

            return self::FAILURE;
        }

        $category = (string) $this->option('category');

        if (! in_array($category, ['jingle', 'shoutout', 'drop', 'id'], true)) {
            $this->error("Unknown category: {$category}");

            return self::FAILURE;
        }

        $root = rtrim($dir, '/'.DIRECTORY_SEPARATOR);

        $files = collect((array) glob($root.DIRECTORY_SEPARATOR.'*'))
            ->filter(fn ($path) => is_string($path) && is_file($path))
            ->filter(fn (string $path) => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                ['mp3', 'wav', 'ogg'],
                true,
            ))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->warn("No audio files found in {$dir}.");

            return self::SUCCESS;
        }

        $titleCounts = [];
        $imported = 0;

        foreach ($files as $src) {
            $title = $this->parseTitle($src);

            // Two clips of the same voice would otherwise collide in the UI.
            $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
            $displayTitle = $titleCounts[$title] > 1 ? "{$title} {$titleCounts[$title]}" : $title;

            $extension = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            $slug = (string) preg_replace('/[^a-z0-9]+/', '_', strtolower($displayTitle));
            $filename = uniqid().'_'.$slug.'.'.$extension;
            $destPath = 'soundbytes/'.$filename;

            $contents = file_get_contents($src);
            if ($contents === false) {
                $this->warn("Failed to read: {$src}");

                continue;
            }

            Storage::disk('public')->put($destPath, $contents);

            MediaAsset::create([
                'type' => MediaType::SoundByte,
                'title' => $displayTitle,
                'filename' => $filename,
                'category' => $category,
                'storage_path' => $destPath,
                'file_size' => strlen($contents),
                'active' => true,
                'needs_pi_download' => true,
            ]);

            $imported++;
            $this->line("  <info>✓</info> {$displayTitle}");
        }

        $this->info("\nDone. {$imported} file(s) imported. Pi will download on next heartbeat.");

        return self::SUCCESS;
    }

    private function parseTitle(string $path): string
    {
        $basename = pathinfo($path, PATHINFO_FILENAME);

        // Strip ElevenLabs_ prefix + timestamp: ElevenLabs_2026-06-28T03_28_56_
        $basename = (string) preg_replace('/^ElevenLabs_\d{4}-\d{2}-\d{2}T[\d_]+_/', '', $basename);

        // Strip generation params suffix: _pvc_... or _pre_...
        $basename = (string) preg_replace('/[_ ]+(pvc|pre)_.+$/', '', $basename);

        // Normalize: trim, collapse spaces, replace underscores with space
        $basename = str_replace('_', ' ', $basename);

        return trim((string) preg_replace('/\s+/', ' ', $basename));
    }
}
