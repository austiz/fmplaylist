<?php

namespace App\Http\Requests\Admin;

use App\Models\PiCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One of the fixed commands a device knows how to run, aimed at a single device. */
class DispatchPiCommandRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'command' => ['required', 'string', Rule::in(PiCommand::DISPATCHABLE)],
            'payload' => ['nullable', 'string', 'max:255'],
        ];
    }
}
