<?php

namespace App\Enums;

/**
 * Every station setting, with its type and default declared once.
 *
 * The settings table is a string KV bag, so each key's real type and default
 * used to live wherever it happened to be read: cast to float in the Pi config,
 * spelled out again as the admin page's default, and a third time as the React
 * form's `?? '96.9'`. The default here is the single source of both:
 * its PHP type *is* the cast.
 */
enum SettingKey: string
{
    // -- Transmission ------------------------------------------------------
    case Frequency = 'frequency';
    case Callsign = 'callsign';
    case FallbackSong = 'fallback_song';
    case FadeInDuration = 'fade_in_duration';

    // -- Scheduling --------------------------------------------------------
    case CommercialInterval = 'commercial_interval';
    case SoundByteInterval = 'sound_byte_interval';

    // -- Broadcast mode and RDS -------------------------------------------
    case BroadcastMode = 'broadcast_mode';
    case LiveStreamUrl = 'live_stream_url';
    case LiveAlsaDevice = 'live_alsa_device';
    case RdsRtMode = 'rds_rt_mode';
    case RdsRt = 'rds_rt';
    case RdsPs = 'rds_ps';
    case EmergencyAnnouncement = 'emergency_announcement';

    // -- Station state the Pi consumes and clears --------------------------
    case PendingWifiSsid = 'pending_wifi_ssid';
    case PendingWifiPassword = 'pending_wifi_password';
    case LastWifiStatus = 'last_wifi_status';

    // -- Rotation counters -------------------------------------------------
    case SongsSinceLastCommercial = 'songs_since_last_commercial';
    case SongsSinceLastSoundByte = 'songs_since_last_sound_byte';
    case ForceCommercialId = 'force_commercial_id';
    case ForceSoundByteId = 'force_sound_byte_id';
    case LastCommercialId = 'last_commercial_id';

    /** The value when the station has no row for this key. Its type is the key's type. */
    public function default(): string|int|float
    {
        return match ($this) {
            self::Frequency => 96.9,
            self::Callsign => '96.9 FM',
            self::FallbackSong => 'FTPA.wav',
            self::FadeInDuration => 0.5,

            self::CommercialInterval, self::SoundByteInterval => 0,

            self::BroadcastMode => 'normal',
            self::LiveStreamUrl => '',
            self::LiveAlsaDevice => 'hw:1,0',
            self::RdsRtMode => 'auto',
            self::RdsRt, self::RdsPs => '',
            self::EmergencyAnnouncement => 'announcement.wav',

            self::PendingWifiSsid, self::PendingWifiPassword, self::LastWifiStatus => '',

            self::SongsSinceLastCommercial, self::SongsSinceLastSoundByte,
            self::ForceCommercialId, self::ForceSoundByteId, self::LastCommercialId => 0,
        };
    }

    /** Turn a stored string into the key's declared type. */
    public function cast(string $raw): string|int|float
    {
        $default = $this->default();

        return match (true) {
            is_int($default) => (int) $raw,
            is_float($default) => (float) $raw,
            default => $raw,
        };
    }

    /** Turn a value of the key's declared type back into a storable string. */
    public function toStorage(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
