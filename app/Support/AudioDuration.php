<?php

namespace App\Support;

class AudioDuration
{
    public static function extract(string $absolutePath): ?int
    {
        $out = shell_exec(
            'ffprobe -v error -show_entries format=duration'
            . ' -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($absolutePath)
        );
        $seconds = (float) trim(is_string($out) ? $out : '');

        return $seconds > 0 ? (int) round($seconds) : null;
    }
}
