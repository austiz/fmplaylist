<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A network saved to the Pi's profile list for it to fall back on. */
class StoreWifiNetworkRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ssid' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.min' => 'WPA passwords must be at least 8 characters. Leave blank for an open network.',
        ];
    }
}
