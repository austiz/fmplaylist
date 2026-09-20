<?php

namespace App\Models;

use App\Enums\MediaType;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Every piece of audio the station can broadcast: songs, commercials and sound
 * bytes in one table, discriminated by `type`.
 *
 * The three used to be separate models with separate controllers, and their
 * upload/sync/delete lifecycles were written out three times in six places. The
 * only thing that actually differed was scheduling, which lives in QueueService.
 * Columns that apply to one type only (`artist`, `category`, `rotation_order`)
 * are nullable here rather than justifying a table of their own.
 *
 * @property MediaType $type
 * @property int|null $duration_seconds
 */
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'artist',
        'filename',
        'storage_path',
        'file_size',
        'duration_seconds',
        'active',
        'category',
        'rds_ps',
        'rotation_order',
        'play_count',
        'needs_pi_download',
        'pi_delete_requested',
    ];

    protected $casts = [
        'type' => MediaType::class,
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'active' => 'boolean',
        'rotation_order' => 'integer',
        'play_count' => 'integer',
        'needs_pi_download' => 'boolean',
        'pi_delete_requested' => 'boolean',
    ];

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

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeOfType(Builder $query, MediaType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Hidden from the public library (songs) or out of rotation (commercials and
     * sound bytes) — one flag, because it always meant the same thing.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeSongs(Builder $query): Builder
    {
        return $query->ofType(MediaType::Song);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('artist', 'like', "%{$term}%");
        });
    }

    /** Next commercial in sequential rotation after the last-played id. Wraps around. */
    public static function nextCommercialInRotation(?int $stationId = null): ?self
    {
        $lastId = (int) Setting::get('last_commercial_id', 0, $stationId);

        $rotation = static::query()->ofType(MediaType::Commercial)->active();

        return (clone $rotation)->where('id', '>', $lastId)->orderBy('rotation_order')->orderBy('id')->first()
            ?? $rotation->orderBy('rotation_order')->orderBy('id')->first();
    }

    public function getDurationFormattedAttribute(): string
    {
        if (! $this->duration_seconds) {
            return '—';
        }

        $m = intdiv($this->duration_seconds, 60);
        $s = $this->duration_seconds % 60;

        return "{$m}:".str_pad((string) $s, 2, '0', STR_PAD_LEFT);
    }
}
