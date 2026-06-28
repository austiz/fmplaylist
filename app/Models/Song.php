<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Song extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'artist',
        'filename',
        'duration_seconds',
        'file_size',
        'available',
        'storage_path',
        'needs_pi_download',
        'pi_delete_requested',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'available' => 'boolean',
        'needs_pi_download' => 'boolean',
        'pi_delete_requested' => 'boolean',
    ];

    public function queueItems(): HasMany
    {
        return $this->hasMany(QueueItem::class);
    }

    public function nowPlaying(): HasOne
    {
        return $this->hasOne(NowPlaying::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('available', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('artist', 'like', "%{$term}%");
        });
    }

    public function getDurationFormattedAttribute(): string
    {
        if (! $this->duration_seconds) {
            return '—';
        }
        $m = intdiv($this->duration_seconds, 60);
        $s = $this->duration_seconds % 60;

        return "{$m}:".str_pad($s, 2, '0', STR_PAD_LEFT);
    }
}
