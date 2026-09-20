<?php

namespace App\Support;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * How long a piece of audio runs, according to ffprobe.
 *
 * Every failure mode returns null rather than throwing: ffprobe not installed (it is
 * not a dependency the app can assume on shared hosting), a file it cannot parse, a
 * hang. A missing duration is cosmetic -- it shows as a blank runtime and
 * `media:backfill-durations` picks it up later -- so it must never be able to take
 * down the thing that called it.
 */
class AudioDuration
{
    private const TIMEOUT_SECONDS = 15;

    public static function extract(string $absolutePath): ?int
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);

        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            // Binary missing, or it outlived the timeout.
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $seconds = (float) trim($process->getOutput());

        return $seconds > 0 ? (int) round($seconds) : null;
    }
}
