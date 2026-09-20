<?php

namespace App\Models;

use Database\Factories\PiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PiToken extends Model
{
    /** @use HasFactory<PiTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'token_hash',
        'label',
        'last_seen_at',
        'pi_status',
        'pi_mode',
        'pi_ip',
        'pi_skip_next',
        'pi_daemon_hash',
        'disk_free_bytes',
        'disk_total_bytes',
        'pi_fm_running',
        'pi_queue_depth',
        'pi_last_error',
        'pi_last_update_status',
        'pi_last_update_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'pi_last_update_at' => 'datetime',
        'pi_fm_running' => 'boolean',
        'pi_skip_next' => 'boolean',
    ];

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /** @return HasMany<DeviceDownload, $this> */
    public function deviceDownloads(): HasMany
    {
        return $this->hasMany(DeviceDownload::class);
    }

    /** @return array{token: self, raw: string} */
    public static function generate(string $label = 'Raspberry Pi', ?int $stationId = null): array
    {
        $raw = Str::random(48);
        $hash = hash('sha256', $raw);

        $attributes = [
            'token_hash' => $hash,
            'label' => $label,
        ];

        $attributes['station_id'] = $stationId ?? Station::defaultId();

        $token = static::create($attributes);

        return ['token' => $token, 'raw' => $raw];
    }

    public static function findByRaw(string $raw): ?self
    {
        return static::where('token_hash', hash('sha256', $raw))->first();
    }

    /** Rotate this device's credential without touching any other device's token. */
    public function regenerateSecret(): string
    {
        $raw = Str::random(48);
        $this->update(['token_hash' => hash('sha256', $raw)]);

        return $raw;
    }
}
