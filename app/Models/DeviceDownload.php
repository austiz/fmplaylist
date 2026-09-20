<?php

namespace App\Models;

use Database\Factories\DeviceDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDownload extends Model
{
    /** @use HasFactory<DeviceDownloadFactory> */
    use HasFactory;

    protected $fillable = ['pi_token_id', 'media_type', 'media_id', 'downloaded_at'];

    protected $casts = [
        'downloaded_at' => 'datetime',
    ];

    /** @return BelongsTo<PiToken, $this> */
    public function piToken(): BelongsTo
    {
        return $this->belongsTo(PiToken::class);
    }
}
