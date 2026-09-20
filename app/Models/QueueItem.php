<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStation;
use Database\Factories\QueueItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A listener request or an autofilled pick. `media_asset` is always a song today;
 * commercials and sound bytes are scheduled directly rather than queued.
 *
 * @property MediaAsset|null $mediaAsset
 */
class QueueItem extends Model
{
    /** @use HasFactory<QueueItemFactory> */
    use BelongsToStation, HasFactory;

    protected $fillable = [
        'station_id',
        'media_asset_id',
        'requested_by_name',
        'position',
        'status',
        'played_at',
        'claimed_by_pi_token_id',
        'claimed_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'played_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    /** @return BelongsTo<MediaAsset, $this> */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
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
     * Pending items this device may take: unclaimed, its own, or claimed so long ago
     * that the device holding them has evidently stopped asking.
     *
     * @param  Builder<QueueItem>  $query
     * @return Builder<QueueItem>
     */
    public function scopeClaimableBy(Builder $query, int $piTokenId): Builder
    {
        return $query->pending()->where(fn (Builder $q) => $q
            ->whereNull('claimed_at')
            ->orWhere('claimed_by_pi_token_id', $piTokenId)
            ->orWhere('claimed_at', '<', now()->subSeconds((int) config('fm.queue_claim_seconds')))
        );
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
