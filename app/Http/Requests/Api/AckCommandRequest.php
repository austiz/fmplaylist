<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** A device reporting the outcome of a command it was handed. */
class AckCommandRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
            'ok' => ['required', 'boolean'],
            // Command output, truncated by the daemon; generous so a failing
            // command's stderr survives to the admin page that displays it.
            'result' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
