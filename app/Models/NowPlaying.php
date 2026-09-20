<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStation;
use Database\Factories\NowPlayingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $type
 */
class NowPlaying extends Model
{
    /** @use HasFactory<NowPlayingFactory> */
    use BelongsToStation, HasFactory;

    protected $table = 'now_playing';

    protected $fillable = [
        'station_id',
        'media_asset_id',
        'queue_item_id',
        'type',
        'started_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    /**
     * Null for commercials and sound bytes, which are announced rather than named.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /** @return BelongsTo<QueueItem, $this> */
    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class);
    }

    /**
     * The one row for a station.
     *
     * Deliberately shadows the query scope the trait supplies: there is at most one
     * now-playing row per station, so every caller wants the model, never a builder.
     */
    public static function forStation(int $stationId): ?self
    {
        return static::query()->forStation($stationId)->with('mediaAsset')->first();
    }
}
