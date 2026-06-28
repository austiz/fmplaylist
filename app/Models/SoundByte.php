<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SoundByte extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'filename', 'category', 'rds_ps', 'storage_path',
        'duration_seconds', 'file_size',
        'active', 'needs_pi_download', 'pi_delete_requested',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'file_size' => 'integer',
        'active' => 'boolean',
        'needs_pi_download' => 'boolean',
        'pi_delete_requested' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
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
