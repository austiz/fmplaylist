<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A one-off "switch to this network now" for the Pi.
 *
 * Unlike a saved network this carries no minimum password length: it is handed
 * straight to the device to try, and rejecting it here would only hide the real
 * failure from the person watching the result come back.
 */
class ConnectWifiRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ssid' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:128'],
        ];
    }
}
