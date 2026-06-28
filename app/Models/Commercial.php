<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Commercial extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'filename', 'storage_path',
        'duration_seconds', 'file_size',
        'active', 'rotation_order', 'play_count',
        'needs_pi_download', 'pi_delete_requested',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'active' => 'boolean',
        'rotation_order' => 'integer',
        'play_count' => 'integer',
        'needs_pi_download' => 'boolean',
        'pi_delete_requested' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Next in sequential rotation after the last-played id. Wraps around. */
    public static function nextInRotation(): ?static
    {
        $lastId = (int) Setting::get('last_commercial_id', 0);

        return static::active()->where('id', '>', $lastId)->orderBy('rotation_order')->orderBy('id')->first()
            ?? static::active()->orderBy('rotation_order')->orderBy('id')->first();
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
