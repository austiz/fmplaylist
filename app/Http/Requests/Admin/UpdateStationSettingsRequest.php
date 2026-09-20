<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The station's own configuration form.
 *
 * The frequency bounds are the FM broadcast band; the interval caps are what the
 * scheduler stays sane at. Zero is a valid interval and means "never".
 */
class UpdateStationSettingsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'frequency' => ['required', 'numeric', 'min:87.5', 'max:108.0'],
            'callsign' => ['required', 'string', 'max:64'],
            'fallback_song' => ['required', 'string', 'max:255'],
            'commercial_interval' => ['required', 'integer', 'min:0', 'max:50'],
            'sound_byte_interval' => ['required', 'integer', 'min:0', 'max:20'],
            'fade_in_duration' => ['required', 'numeric', 'min:0', 'max:3'],
        ];
    }
}
