<?php

namespace App\Enums;

/**
 * The three kinds of audio the station broadcasts.
 *
 * They differ only in *scheduling policy* — songs come from the queue, commercials
 * run on an interval in rotation, sound bytes run on their own interval at random.
 * Everything else (upload, storage, duration probing, per-device download tracking,
 * delete-after-the-Pi-confirms) is identical, which is why they share one table and
 * one controller. The per-type knobs that genuinely differ live here.
 */
enum MediaType: string
{
    case Song = 'song';
    case Commercial = 'commercial';
    case SoundByte = 'sound_byte';

    public function label(): string
    {
        return match ($this) {
            self::Song => 'Song',
            self::Commercial => 'Commercial',
            self::SoundByte => 'Sound byte',
        };
    }

    /** Sub-directory on the `public` disk. The Pi flattens these into one folder. */
    public function storageDirectory(): string
    {
        return match ($this) {
            self::Song => 'songs',
            self::Commercial => 'commercials',
            self::SoundByte => 'soundbytes',
        };
    }

    /** Ceiling in kilobytes, as Laravel's `max:` rule wants it. */
    public function maxUploadKb(): int
    {
        return match ($this) {
            self::Song => (int) config('fm.uploads.song_max_kb'),
            self::Commercial => (int) config('fm.uploads.commercial_max_kb'),
            self::SoundByte => (int) config('fm.uploads.sound_byte_max_kb'),
        };
    }

    /**
     * Songs are decoded by the transmitter's own player, which never handled ogg —
     * the shorter stings are mixed in separately and can.
     */
    public function allowedExtensions(): string
    {
        return match ($this) {
            self::Song => 'wav,mp3',
            self::Commercial, self::SoundByte => 'wav,mp3,ogg',
        };
    }

    /** The URL segment each type is administered under, e.g. /admin/sound-bytes. */
    public function routeSegment(): string
    {
        return match ($this) {
            self::Song => 'songs',
            self::Commercial => 'commercials',
            self::SoundByte => 'sound-bytes',
        };
    }
}
