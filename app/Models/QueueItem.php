<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueItem extends Model
{
    protected $fillable = [
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

    /**
     * @param Builder<QueueItem> $query
     * @return Builder<QueueItem>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->orderBy('position');
    }

    /**
     * @param Builder<QueueItem> $query
     * @return Builder<QueueItem>
     */
    public function scopePlayed(Builder $query): Builder
    {
        return $query->where('status', 'played')->orderByDesc('played_at');
    }
}
