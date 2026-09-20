<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Everything a device reports about itself on its 30-second poll.
 *
 * Every field but `status` and `mode` is telemetry: an older daemon that has not
 * been updated yet simply omits the ones it does not know about, so they are all
 * nullable and the handler falls back to the token's stored value.
 */
class HeartbeatRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:idle,playing,live'],
            'mode' => ['required', 'string', 'max:30'],
            'ip' => ['nullable', 'string', 'max:45'],
            'wifi_ssid' => ['nullable', 'string', 'max:100'],
            'wifi_networks' => ['nullable', 'array'],
            'wifi_applied' => ['nullable', 'string', 'max:100'],
            'wifi_failed' => ['nullable', 'string', 'max:100'],
            'daemon_hash' => ['nullable', 'string', 'max:16'],
            'disk_free_bytes' => ['nullable', 'integer'],
            'disk_total_bytes' => ['nullable', 'integer'],
            'wifi_profiles_rev' => ['nullable', 'string', 'max:32'],
            'last_error_kind' => ['nullable', 'string', 'max:20'],
            'last_error_message' => ['nullable', 'string', 'max:255'],
            'last_error_at' => ['nullable', 'string', 'max:32'],
            // Sent by the daemon since forever, silently dropped until now.
            'fm_running' => ['nullable', 'boolean'],
            'schedule_queue_depth' => ['nullable', 'integer'],
            'ready_queue_depth' => ['nullable', 'integer'],
            'last_update_result' => ['nullable', 'string', 'max:20'],
        ];
    }
}
