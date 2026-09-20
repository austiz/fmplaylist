<?php

namespace App\Models;

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
    use HasFactory;

    protected $table = 'now_playing';

    protected $fillable = [
        'station_id',
        'song_id',
        'queue_item_id',
        'type',
        'started_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
    ];

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return BelongsTo<QueueItem, $this> */
    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(QueueItem::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public static function forStation(int $stationId): ?self
    {
        return static::with('song')->where('station_id', $stationId)->first();
    }
}
