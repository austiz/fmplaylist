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

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')->orderBy('position');
    }

    public function scopePlayed(Builder $query): Builder
    {
        return $query->where('status', 'played')->orderByDesc('played_at');
    }
}
