<?php

namespace App\Models;

use Database\Factories\QueueItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Song|null $song
 */
class QueueItem extends Model
{
    /** @use HasFactory<QueueItemFactory> */
    use HasFactory;

    protected $fillable = [
        'station_id',
        'song_id',
        'requested_by_name',
        'position',
        'status',
        'played_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'played_at' => 'datetime',
    ];

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * @param  Builder<QueueItem>  $query
     * @return Builder<QueueItem>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->orderBy('position');
    }

    /**
     * @param  Builder<QueueItem>  $query
     * @return Builder<QueueItem>
     */
    public function scopeForStation(Builder $query, int $stationId): Builder
    {
        return $query->where('station_id', $stationId);
    }

    /**
     * Filter only, deliberately no ordering: an ORDER BY bundled into a status
     * scope follows callers into aggregate queries, where MySQL's default
     * ONLY_FULL_GROUP_BY rejects it outright. Callers that want recency say so.
     *
     * @param  Builder<QueueItem>  $query
     * @return Builder<QueueItem>
     */
    public function scopePlayed(Builder $query): Builder
    {
        return $query->where('status', 'played');
    }
}
