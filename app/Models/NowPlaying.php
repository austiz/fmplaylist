<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NowPlaying extends Model
{
    protected $table = 'now_playing';

    protected $fillable = [
        'song_id',
        'queue_item_id',
        'type',
        'started_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class);
    }
}
