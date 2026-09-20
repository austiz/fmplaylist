<?php

namespace App\Models;

use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;

/**
 * A broadcast identity: its own settings, request queue, and now-playing state.
 * Not to be confused with the unrelated `station_ids` table (RDS "Station ID" audio idents).
 */
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /** @return HasMany<PiToken, $this> */
    public function piTokens(): HasMany
    {
        return $this->hasMany(PiToken::class);
    }

    /** @return HasMany<QueueItem, $this> */
    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    /** @return HasOne<NowPlaying, $this> */
    public function nowPlaying(): HasOne
    {
        return $this->hasOne(NowPlaying::class);
    }

    public static function defaultId(): ?int
    {
        return Cache::rememberForever('station.default_id', function () {
            return static::where('is_default', true)->value('id')
                ?? static::orderBy('id')->value('id');
        });
    }

    public static function forgetDefaultIdCache(): void
    {
        Cache::forget('station.default_id');
    }

    /**
     * defaultId() caches forever, so any write that could change which station is
     * default has to clear it. Doing that here rather than at each call site means
     * a new admin action cannot silently leave the cache stale.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => self::forgetDefaultIdCache());
        static::deleted(static fn () => self::forgetDefaultIdCache());
    }
}
