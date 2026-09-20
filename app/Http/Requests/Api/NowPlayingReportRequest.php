<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** A device reporting what it just started playing. */
class NowPlayingReportRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:song,station_id,commercial,sound_byte'],
            'queue_item_id' => ['nullable', 'integer'],
            'song_filename' => ['nullable', 'string'],
            'item_id' => ['nullable', 'integer'],
        ];
    }
}
