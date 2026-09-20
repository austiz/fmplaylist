<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStation;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use BelongsToStation, HasFactory;

    protected $fillable = ['station_id', 'name', 'message'];
}
