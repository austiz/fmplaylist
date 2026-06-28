<?php

namespace App\Console\Commands;

use App\Models\SoundByte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeedSoundBytes extends Command
{
    protected $signature = 'soundbytes:seed {--dir= : Directory containing MP3 files}';
    protected $description = 'Import ElevenLabs MP3 files as sound bytes';

    public function handle(): int
    {
        $files = [
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T03_28_56_Brad - Eyewitness Bystander _pvc_sp120_s10_sb100_se100_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T03_22_30_Brad - Eyewitness Bystander _pvc_sp120_s10_sb100_se100_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T03_18_56_Brad - Eyewitness Bystander _pvc_sp120_s10_sb100_se100_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T02_39_47_Paul Hampton - Sharp Talk Show Host_pvc_sp110_s50_sb91_se50_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T02_37_21_Paul Hampton - Sharp Talk Show Host_pvc_sp110_s52_sb91_se37_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T02_36_39_Paul Hampton - Sharp Talk Show Host_pvc_sp110_s52_sb91_se37_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T02_30_51_Taylor Andrew - Engaging Podcast Host_pvc_sp110_s71_sb61_se58_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T02_06_53_Nubee – Sarcastic Podcast Host_pvc_sp79_s60_sb62_se62_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_56_51_Blondie - Radio Host_pvc_sp82_s50_sb75_se45_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_55_01_David - The Concert Host_pvc_sp79_s60_sb79_se43_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_48_56_David - The Concert Host_pvc_sp79_s60_sb79_se43_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_47_23_David - The Concert Host_pvc_sp79_s60_sb79_se43_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_45_11_Blondie - Radio Host_pvc_sp100_s50_sb75_se45_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_39_55_Jamal _ From the streets_pvc_sp100_s63_sb75_se0_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_36_24_Austiz_pvc_sp75_s49_sb75_se19_b_m2.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T01_23_02_Liam - Energetic, Social Media Creator_pre_sp79_s60_sb79_v3.mp3',
            'C:\\Users\\austi\\Downloads\\ElevenLabs_2026-06-28T03_33_41_David - Deep, Warm, Narration_pvc_sp70_s100_sb100_se100_b_m2.mp3',
        ];

        // Track title counts for duplicate disambiguation
        $titleCounts = [];

        foreach ($files as $src) {
            if (! file_exists($src)) {
                $this->warn("Skipping (not found): {$src}");
                continue;
            }

            $title = $this->parseTitle($src);

            // Count and disambiguate duplicates
            $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
            $displayTitle = $titleCounts[$title] > 1 ? "{$title} {$titleCounts[$title]}" : $title;

            $filename = time() . '_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($displayTitle)) . '.mp3';
            $destPath = 'soundbytes/' . $filename;

            $contents = file_get_contents($src);
            if ($contents === false) {
                $this->warn("Failed to read: {$src}");
                continue;
            }
            Storage::disk('public')->put($destPath, $contents);

            SoundByte::create([
                'title'             => $displayTitle,
                'filename'          => $filename,
                'category'          => 'id',
                'storage_path'      => $destPath,
                'file_size'         => filesize($src),
                'active'            => true,
                'needs_pi_download' => true,
            ]);

            // Ensure unique filenames when loop is fast
            usleep(1000);

            $this->line("  <info>✓</info> {$displayTitle}");
        }

        // Second pass: disambiguate titles that only appeared once but were pre-counted
        // (re-deduplicate in DB for titles that ended up unique)
        foreach ($titleCounts as $title => $count) {
            if ($count === 1) {
                // Strip the trailing " 1" if we accidentally added it — but we only add it when count > 1, so nothing to do
                continue;
            }
        }

        $this->info("\nDone. " . count($files) . " files processed. Pi will download on next heartbeat.");

        return self::SUCCESS;
    }

    private function parseTitle(string $path): string
    {
        $basename = pathinfo($path, PATHINFO_FILENAME);

        // Strip ElevenLabs_ prefix + timestamp: ElevenLabs_2026-06-28T03_28_56_
        $basename = preg_replace('/^ElevenLabs_\d{4}-\d{2}-\d{2}T[\d_]+_/', '', $basename);

        // Strip generation params suffix: _pvc_... or _pre_...
        $basename = preg_replace('/[_ ]+(pvc|pre)_.+$/', '', $basename);

        // Normalize: trim, collapse spaces, replace underscores with space
        $basename = str_replace('_', ' ', $basename);
        $basename = trim(preg_replace('/\s+/', ' ', $basename));

        return $basename;
    }
}
