<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** What the transmitter puts on air: the library, or a live input. */
class SetBroadcastModeRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'broadcast_mode' => ['required', 'in:normal,phone_stream,usb_input,custom_stream'],
            'live_stream_url' => ['nullable', 'string', 'max:255'],
            'live_alsa_device' => ['nullable', 'string', 'max:50'],
        ];
    }
}
