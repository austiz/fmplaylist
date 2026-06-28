<?php

namespace App\Console\Commands;

use App\Models\Commercial;
use App\Models\Song;
use App\Models\SoundByte;
use App\Support\AudioDuration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillDurations extends Command
{
    protected $signature = 'media:backfill-durations';

    protected $description = 'Run ffprobe on uploaded media that is missing duration_seconds';

    public function handle(): int
    {
        $updated = 0;
        $skipped = 0;

        $models = [
            'songs'       => Song::whereNull('duration_seconds')->whereNotNull('storage_path')->get(),
            'commercials' => Commercial::whereNull('duration_seconds')->whereNotNull('storage_path')->get(),
            'sound_bytes' => SoundByte::whereNull('duration_seconds')->whereNotNull('storage_path')->get(),
        ];

        foreach ($models as $label => $items) {
            foreach ($items as $item) {
                $abs = Storage::disk('public')->path($item->storage_path);

                if (! file_exists($abs)) {
                    $this->line("  skip {$label}:{$item->id} — file missing");
                    $skipped++;
                    continue;
                }

                $seconds = AudioDuration::extract($abs);

                if ($seconds === null) {
                    $this->line("  skip {$label}:{$item->id} — ffprobe returned null");
                    $skipped++;
                    continue;
                }

                $item->update(['duration_seconds' => $seconds]);
                $this->line("  {$label}:{$item->id} = {$seconds}s");
                $updated++;
            }
        }

        $this->info("Done — updated: {$updated}, skipped: {$skipped}");

        return 0;
    }
}
