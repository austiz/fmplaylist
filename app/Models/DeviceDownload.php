<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDownload extends Model
{
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
